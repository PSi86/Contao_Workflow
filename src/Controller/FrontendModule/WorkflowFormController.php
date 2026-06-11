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
use Psimandl\WorkflowBundle\Form\QuestionWidgetFactory;
use Psimandl\WorkflowBundle\Model\EntryModel;
use Psimandl\WorkflowBundle\Model\QuestionModel;
use Psimandl\WorkflowBundle\Model\WorkflowModel;
use Psimandl\WorkflowBundle\Service\DocumentBodyComposer;
use Psimandl\WorkflowBundle\Service\SubmissionProcessor;
use Psimandl\WorkflowBundle\Service\WorkflowStatus;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfToken;

#[AsFrontendModule(type: 'workflow_form', category: 'application', template: 'mod_workflow_form')]
class WorkflowFormController extends AbstractFrontendModuleController
{
    /** Reject signatures larger than this (base64 data-URI length) – DoS/tamper guard. */
    private const MAX_SIGNATURE_BYTES = 1_500_000;

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly SubmissionProcessor $submissionProcessor,
        private readonly DocumentBodyComposer $bodyComposer,
        private readonly QuestionWidgetFactory $widgetFactory,
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
        $GLOBALS['TL_JAVASCRIPT'][] = $assetDir.'/workflow-form.js';

        $token = (string) $this->framework->getAdapter(Input::class)->get('auto_item');

        $template->set('requestToken', $this->csrfTokenManager->getDefaultTokenValue());
        $template->set('error', '');
        $template->set('heading', '');
        $template->set('intro', '');
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

        // Heading + intro are the same texts the PDF shows (resolved by the
        // shared composer), so the form page mirrors the document.
        $data = $entry->getData();
        $extra = $workflow->getMasterVars();
        $template->set('heading', $this->bodyComposer->resolveHeading($workflow, $data, $extra, (string) $entry->email));
        $template->set('intro', $this->bodyComposer->resolveIntro($workflow, $data, $extra, (string) $entry->email));

        if ((int) $entry->status >= WorkflowStatus::STATUS_RESPONDED) {
            $template->set('state', 'done');

            return $template->getResponse();
        }

        $questions = $workflow->getQuestions();

        $template->set('email', $entry->email);
        $template->set('questions', $this->buildQuestionViews($questions, $workflow, $entry));
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
     * @return array<int, array<string, mixed>>
     */
    private function buildQuestionViews(array $questions, WorkflowModel $workflow, EntryModel $entry): array
    {
        $views = [];
        $data = $entry->getData();
        $extra = $workflow->getMasterVars();
        $email = (string) $entry->email;

        foreach ($questions as $question) {
            // "Aktuelle Zeit" fields flagged hidden never appear in the form –
            // they are filled automatically on submission.
            if ($question->isHiddenInForm()) {
                continue;
            }

            $storage = trim((string) $question->storageField);

            // The statement parts let the form show exactly the text the
            // document will contain (live-updated in the browser).
            $parts = $this->bodyComposer->statementParts($question, $workflow, $data, $extra, $email);

            $options = [];

            foreach ($question->getOptions() as $option) {
                $option['statement'] = $parts['options'][$option['value']] ?? $option['label'];
                $options[] = $option;
            }

            $autoValue = $question->isCurrentTime() ? date('d.m.Y') : '';
            $readOnly = $question->isReadOnly() && !$question->isCurrentTime();
            $storedValue = '' !== $storage ? trim((string) ($data[$storage] ?? '')) : '';

            // Static statement for fields the user cannot change (the JS hint
            // only tracks editable inputs).
            $staticValue = '' !== $autoValue ? $autoValue : ($readOnly ? $storedValue : '');

            $views[] = [
                'id'                => (int) $question->id,
                'label'             => (string) $question->label,
                'type'              => (string) $question->type,
                'mandatory'         => !$readOnly && $question->isMandatory(),
                'multiple'          => $question->isMultiple(),
                'readOnly'          => $readOnly,
                'options'           => $options,
                'autoValue'         => $autoValue,
                'initial'           => $this->resolveInitialValue($question, $data),
                // Hint only when a statement was explicitly configured – without
                // one the visible label/option text counts verbatim anyway.
                'hasStatement'      => $question->hasExplicitStatement(),
                'statementTemplate' => $parts['template'],
                'statement'         => '' !== $staticValue
                    ? $this->bodyComposer->renderStatement($question, $staticValue, $data, $extra, $email, (string) $workflow->title)
                    : '',
            ];
        }

        return $views;
    }

    /**
     * Prefill value of a question from the entry data (Excel source value or a
     * previously stored answer). Choice values must match a configured option
     * (exactly, then trimmed/case-insensitively); on no match the prefill is
     * discarded – never silently invent a selection.
     *
     * @param array<string, mixed> $data
     *
     * @return string|array<int, string>
     */
    private function resolveInitialValue(QuestionModel $question, array $data): string|array
    {
        $empty = $question->isMultiple() ? [] : '';

        // Read-only fields always show the stored value; otherwise the prefill
        // flag decides.
        if (!$question->isPrefilled() && !$question->isReadOnly()) {
            return $empty;
        }

        $storage = trim((string) $question->storageField);
        $value = '' !== $storage ? trim((string) ($data[$storage] ?? '')) : '';

        if ('' === $value) {
            return $empty;
        }

        if ($question->isMultiple()) {
            $values = [];

            foreach (preg_split('/\s*,\s*/', $value) ?: [] as $single) {
                $match = $this->matchOption($question, $single);

                if (null !== $match && !\in_array($match, $values, true)) {
                    $values[] = $match;
                }
            }

            return $values;
        }

        if ($question->hasOptions()) {
            return $this->matchOption($question, $value) ?? '';
        }

        // The HTML date input needs ISO format; unparseable values are discarded.
        if ('date' === (string) $question->type) {
            return $this->toIsoDate($value);
        }

        return $value;
    }

    /**
     * Canonical option value for a prefill candidate, or null when it matches
     * no configured option.
     */
    private function matchOption(QuestionModel $question, string $value): ?string
    {
        $allowed = $question->getAllowedValues();

        if (\in_array($value, $allowed, true)) {
            return $value;
        }

        foreach ($allowed as $candidate) {
            if (0 === strcasecmp(trim($candidate), $value)) {
                return $candidate;
            }
        }

        return null;
    }

    private function toIsoDate(string $value): string
    {
        foreach (['Y-m-d', 'd.m.Y', 'j.n.Y', 'm/d/Y'] as $format) {
            $date = \DateTime::createFromFormat('!'.$format, $value);

            if ($date instanceof \DateTime) {
                return $date->format('Y-m-d');
            }
        }

        return '';
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
            $storage = trim((string) $question->storageField);

            // Read-only fields are output only – never validated, never stored.
            if ($question->isReadOnly() && !$question->isCurrentTime()) {
                continue;
            }

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

            // Contao's widget layer validates (mandatory, option whitelist)
            // with the localised ERR.* messages.
            $widget = $this->widgetFactory->create($question);

            if (null === $widget) {
                continue;
            }

            $widget->validate();

            if ($question->isMultiple()) {
                $values = array_values(array_filter(
                    array_map('strval', (array) $widget->value),
                    static fn (string $v): bool => '' !== $v,
                ));
                $submitted[(int) $question->id] = $values;

                if ($widget->hasErrors()) {
                    return (string) $widget->getErrorAsString();
                }

                if ('' !== $storage) {
                    $answers[$storage] = implode(', ', $values);
                }

                continue;
            }

            $value = trim((string) (\is_array($widget->value) ? '' : $widget->value));
            $submitted[(int) $question->id] = $value;

            if ($widget->hasErrors()) {
                return (string) $widget->getErrorAsString();
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
     *                           or unusable (too large / not a genuine PNG)
     */
    private function resolveSignature(Request $request, bool $required): string|null|false
    {
        $signature = trim((string) $request->request->get('signature'));

        if ('' === $signature) {
            return $required ? false : null;
        }

        // Accept only a reasonably sized, genuine base64 PNG – the signature pad
        // emits canvas.toDataURL('image/png'). Length is checked first so an
        // oversized payload is rejected before any decoding. Guards against DoS
        // (huge blobs in the longtext column / on disk) and tampered, non-image
        // data before it is stored or embedded in the PDF. These checks are pure
        // string/byte comparisons – no code execution.
        if (\strlen($signature) > self::MAX_SIGNATURE_BYTES || !$this->isValidPngDataUri($signature)) {
            return $required ? false : null;
        }

        return $signature;
    }

    private function isValidPngDataUri(string $signature): bool
    {
        $prefix = 'data:image/png;base64,';

        if (!str_starts_with($signature, $prefix)) {
            return false;
        }

        $binary = base64_decode(substr($signature, \strlen($prefix)), true);

        // PNG magic bytes.
        return false !== $binary && str_starts_with($binary, "\x89PNG\r\n\x1a\n");
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
