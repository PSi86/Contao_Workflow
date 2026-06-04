<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Controller\FrontendModule;

use Contao\CoreBundle\Controller\FrontendModule\AbstractFrontendModuleController;
use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\DependencyInjection\Attribute\AsFrontendModule;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Contao\Input;
use Contao\ModuleModel;
use Psimandl\WorkflowBundle\Model\EntryModel;
use Psimandl\WorkflowBundle\Model\QuestionModel;
use Psimandl\WorkflowBundle\Model\WorkflowModel;
use Psimandl\WorkflowBundle\Service\SubmissionProcessor;
use Psimandl\WorkflowBundle\Service\WorkflowStatus;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfToken;

#[AsFrontendModule(type: 'workflow_form', category: 'application', template: 'mod_workflow_form')]
class WorkflowFormController extends AbstractFrontendModuleController
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly SubmissionProcessor $submissionProcessor,
        private readonly ContaoCsrfTokenManager $csrfTokenManager,
        private readonly string $csrfTokenName,
    ) {
    }

    protected function getResponse(FragmentTemplate $template, ModuleModel $model, Request $request): Response
    {
        $this->framework->initialize();

        $assetDir = 'bundles/contaoworkflow';
        $GLOBALS['TL_CSS'][] = $assetDir.'/workflow-form.css';
        $GLOBALS['TL_JAVASCRIPT'][] = $assetDir.'/workflow-signature.js';

        $token = (string) $this->framework->getAdapter(Input::class)->get('auto_item');

        $template->set('requestToken', $this->csrfTokenManager->getDefaultTokenValue());
        $template->set('error', '');
        $template->set('inputFields', []);
        $template->set('data', []);
        $template->set('questions', []);
        $template->set('answers', []);
        $template->set('requireSignature', true);

        $entry = EntryModel::findByToken($token);

        if (null === $entry) {
            $template->set('state', 'invalid');

            return $template->getResponse();
        }

        $workflow = WorkflowModel::findByPk((int) $entry->pid);

        if (null === $workflow || !$workflow->published) {
            $template->set('state', 'invalid');

            return $template->getResponse();
        }

        if ((int) $entry->status >= WorkflowStatus::STATUS_RESPONDED) {
            $template->set('state', 'done');

            return $template->getResponse();
        }

        $questions = $workflow->getQuestions();

        $template->set('inputFields', $workflow->getInputFields());
        $template->set('data', $entry->getData());
        $template->set('email', $entry->email);
        $template->set('questions', $this->buildQuestionViews($questions));
        $template->set('requireSignature', $workflow->isSignatureRequired());
        $template->set('formId', 'workflow_form_'.$entry->id);
        $template->set('state', 'form');

        if ($this->isSubmission($request, (int) $entry->id)) {
            $submitted = [];
            $error = $this->handleSubmission($request, $workflow, $entry, $questions, $submitted);

            if ('' === $error) {
                $template->set('state', 'submitted');

                return $template->getResponse();
            }

            $template->set('error', $error);
            $template->set('answers', $submitted);
        }

        return $template->getResponse();
    }

    /**
     * @param array<int, QuestionModel> $questions
     *
     * @return array<int, array{id: int, label: string, type: string, mandatory: bool, multiple: bool, options: array<int, array{value: string, label: string}>}>
     */
    private function buildQuestionViews(array $questions): array
    {
        $views = [];

        foreach ($questions as $question) {
            // "Aktuelle Zeit" fields flagged hidden never appear in the form –
            // they are filled automatically on submission.
            if ($question->isHiddenInForm()) {
                continue;
            }

            $views[] = [
                'id'        => (int) $question->id,
                'label'     => (string) $question->label,
                'type'      => (string) $question->type,
                'mandatory' => $question->isMandatory(),
                'multiple'  => $question->isMultiple(),
                'options'   => $question->getOptions(),
                'autoValue' => $question->isCurrentTime() ? date('d.m.Y') : '',
            ];
        }

        return $views;
    }

    /**
     * @param array<int, QuestionModel>          $questions
     * @param array<int, string|array<string>>   $submitted   filled with the raw submitted values for repopulation
     */
    private function handleSubmission(Request $request, WorkflowModel $workflow, EntryModel $entry, array $questions, array &$submitted): string
    {
        $submittedToken = (string) $request->request->get('REQUEST_TOKEN');

        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken($this->csrfTokenName, $submittedToken))) {
            return 'Die Sitzung ist abgelaufen. Bitte versuchen Sie es erneut.';
        }

        $answers = [];

        foreach ($questions as $question) {
            $name = 'q_'.$question->id;
            $storage = trim((string) $question->storageField);

            // "Aktuelle Zeit": always set to the current date on submission,
            // regardless of (and ignoring) any posted value – no validation.
            if ($question->isCurrentTime()) {
                $value = date('d.m.Y');
                $submitted[(int) $question->id] = $value;

                if ('' !== $storage) {
                    $answers[$storage] = $value;
                }

                continue;
            }

            if ($question->isMultiple()) {
                $values = array_values(array_filter(
                    array_map('strval', $request->request->all($name)),
                    static fn (string $v): bool => '' !== $v,
                ));
                $submitted[(int) $question->id] = $values;

                foreach ($values as $value) {
                    if (!\in_array($value, $question->getAllowedValues(), true)) {
                        return $this->invalidChoice($question);
                    }
                }

                if ($question->isMandatory() && [] === $values) {
                    return $this->missingValue($question);
                }

                if ('' !== $storage) {
                    $answers[$storage] = implode(', ', $values);
                }

                continue;
            }

            $value = trim((string) $request->request->get($name, ''));
            $submitted[(int) $question->id] = $value;

            if ($question->hasOptions() && '' !== $value && !\in_array($value, $question->getAllowedValues(), true)) {
                return $this->invalidChoice($question);
            }

            if ($question->isMandatory() && '' === $value) {
                return $this->missingValue($question);
            }

            if ('date' === $question->type && '' !== $value) {
                $value = $this->normalizeDate($value);
            }

            if ('' !== $storage) {
                $answers[$storage] = $value;
            }
        }

        $signature = $this->resolveSignature($request, $workflow->isSignatureRequired());

        if (false === $signature) {
            return 'Bitte unterschreiben Sie im dafür vorgesehenen Feld.';
        }

        $this->submissionProcessor->process($workflow, $entry, $answers, $signature);

        return '';
    }

    /**
     * @return string|null|false the signature data URI, null when not provided
     *                           (and not required), or false when required but missing
     */
    private function resolveSignature(Request $request, bool $required): string|null|false
    {
        $signature = (string) $request->request->get('signature');
        $hasSignature = '' !== trim($signature) && str_contains($signature, 'base64,');

        if ($required && !$hasSignature) {
            return false;
        }

        return $hasSignature ? $signature : null;
    }

    private function invalidChoice(QuestionModel $question): string
    {
        return sprintf('Ungültige Auswahl bei „%s".', (string) $question->label);
    }

    private function missingValue(QuestionModel $question): string
    {
        return sprintf('Bitte füllen Sie das Feld „%s" aus.', (string) $question->label);
    }

    /**
     * Normalises a submitted date (HTML5 date input yields Y-m-d) to d.m.Y,
     * matching the format used elsewhere; leaves unparseable input untouched.
     */
    private function normalizeDate(string $value): string
    {
        foreach (['Y-m-d', 'd.m.Y', 'j.n.Y', 'm/d/Y'] as $format) {
            $date = \DateTime::createFromFormat($format, $value);

            if ($date instanceof \DateTime) {
                return $date->format('d.m.Y');
            }
        }

        return $value;
    }

    private function isSubmission(Request $request, int $entryId): bool
    {
        return $request->isMethod('POST')
            && 'workflow_form_'.$entryId === (string) $request->request->get('FORM_SUBMIT');
    }
}
