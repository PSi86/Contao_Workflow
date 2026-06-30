<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\FrontendTemplate;
use Psimandl\WorkflowBundle\Model\EntryModel;
use Psimandl\WorkflowBundle\Model\QuestionModel;
use Psimandl\WorkflowBundle\Model\WorkflowModel;

/**
 * Single place that composes the document body. Whatever renders the body of a
 * workflow document (the PDF today, a possible preview tomorrow) must go
 * through this service so the result is identical everywhere – this is what
 * guarantees the form/PDF parity.
 *
 * - Template mode: the selected body template handles everything itself
 *   (it receives all data, incl. answers, and branches internally) – PDF
 *   rules are NOT consulted.
 * - Letter mode: the shared heading comes from the workflow; the body text
 *   comes from the first matching PDF rule (a rule without conditions is the
 *   "else" case). If no rule matches, the body stays empty.
 */
class DocumentBodyComposer
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly RuleEvaluator $ruleEvaluator,
        private readonly PlaceholderResolver $placeholderResolver,
    ) {
    }

    /**
     * @param array<string, mixed>  $data  entry data (source columns + answers)
     * @param array<string, string> $extra master/letterhead variables
     */
    public function compose(WorkflowModel $workflow, EntryModel $entry, array $data, array $extra): string
    {
        $email = (string) $entry->email;
        $statements = $this->statementTokens($workflow, $data, $extra, $email);

        if ('template' === (string) $workflow->pdfBodyType && '' !== (string) $workflow->pdfBodyTemplate) {
            /** @var FrontendTemplate $bodyTpl */
            $bodyTpl = $this->framework->createInstance(FrontendTemplate::class, [(string) $workflow->pdfBodyTemplate]);
            $bodyTpl->setData([
                'data'       => $data,
                'extra'      => $extra,
                'statements' => $statements,
                'heading'    => $this->resolveHeading($workflow, $data, $extra, $email),
                'intro'      => $this->resolveIntro($workflow, $data, $extra, $email),
            ]);

            return $bodyTpl->parse();
        }

        // Letter mode: shared heading + intro from the workflow (also shown in
        // the form), body text from the rule. Placeholders resolve through the
        // shared PlaceholderResolver, so the same ##data_*##/##letterhead_*## tokens
        // work here, in the mails and in the export.
        $rule = $this->ruleEvaluator->resolveRule($workflow, $entry);
        $body = null !== $rule ? $rule->getPdfBody() : '';

        $esc = fn (string $value): string => $this->esc($value);
        $title = (string) $workflow->title;

        $renderedTitle = $esc($this->resolveHeading($workflow, $data, $extra, $email));
        $renderedIntro = nl2br($esc($this->resolveIntro($workflow, $data, $extra, $email)));
        $renderedBody = nl2br($this->placeholderResolver->renderPdfText($body, $data, $extra, $email, $title, $esc, $statements));

        return ('' !== $renderedTitle ? '<h1>'.$renderedTitle.'</h1>' : '')
            .('' !== $renderedIntro ? '<div class="letter-intro">'.$renderedIntro.'</div>' : '')
            .'<div class="letter-body">'.$renderedBody.'</div>';
    }

    /**
     * The shared heading (workflow "Überschrift"), tokens resolved, plain text.
     * Used identically by the form and the PDF – no ##text_*## tokens, the form
     * shows it before the questions are answered.
     *
     * @param array<string, mixed>  $data
     * @param array<string, string> $extra
     */
    public function resolveHeading(WorkflowModel $workflow, array $data, array $extra, string $email): string
    {
        return trim($this->placeholderResolver->fill((string) $workflow->pdfTitle, $data, $extra, $email, (string) $workflow->title));
    }

    /**
     * The optional intro paragraph after the heading, tokens resolved, plain
     * text (multi-line). Shared between form and PDF like the heading.
     *
     * @param array<string, mixed>  $data
     * @param array<string, string> $extra
     */
    public function resolveIntro(WorkflowModel $workflow, array $data, array $extra, string $email): string
    {
        return trim($this->placeholderResolver->fill((string) $workflow->introText, $data, $extra, $email, (string) $workflow->title));
    }

    /**
     * Statement ("Textbaustein") tokens for an entry's data, raw/plain text:
     * ##text_<storage-slug>## per question and ##text_all## (all statements in
     * question order). The statement of a question is the text the participant
     * saw in the form – the option statement/label for choice questions, the
     * ##answer## template for value questions – so a rule body built from these
     * tokens matches the form by construction.
     *
     * ##text_all## formatting: default statements ("label: value") follow each
     * other line by line; a statement with an explicitly configured document
     * text gets a blank line before it (it is a full sentence, not a list row).
     *
     * Questions hidden in the form (auto-filled "Aktuelle Zeit") are excluded
     * from ##text_all##: the participant never saw them.
     *
     * @param array<string, mixed>  $data
     * @param array<string, string> $extra
     *
     * @return array<string, string>
     */
    public function statementTokens(WorkflowModel $workflow, array $data, array $extra, string $email): array
    {
        $tokens = [];
        $all = '';
        $title = (string) $workflow->title;

        foreach ($workflow->getQuestions() as $question) {
            $storage = trim((string) $question->storageField);

            if ('' === $storage) {
                continue;
            }

            $statement = $this->renderStatement($question, (string) ($data[$storage] ?? ''), $data, $extra, $email, $title);
            $tokens['text_'.$this->placeholderResolver->normalize($storage)] = $statement;

            if ('' === $statement || $question->isHiddenInForm()) {
                continue;
            }

            if ('' === $all) {
                $all = $statement;
            } else {
                $all .= ($question->hasExplicitStatement() ? "\n\n" : "\n").$statement;
            }
        }

        $tokens['text_all'] = $all;

        return $tokens;
    }

    /**
     * Statement building blocks for the front-end form, so the form can show
     * (and live-update) exactly the text the document will contain: all
     * ##data_*##/##letterhead_*## tokens are already resolved, only ##answer## is left
     * for the browser to substitute.
     *
     * @param array<string, mixed>  $data
     * @param array<string, string> $extra
     *
     * @return array{template: string, options: array<string, string>}
     */
    public function statementParts(QuestionModel $question, WorkflowModel $workflow, array $data, array $extra, string $email): array
    {
        $title = (string) $workflow->title;

        if ($question->hasOptions()) {
            $options = [];

            foreach ($question->getOptions() as $option) {
                $options[$option['value']] = $this->resolveStatementText(
                    $question->getOptionStatement($option['value']),
                    $option['value'],
                    $data,
                    $extra,
                    $email,
                    $title,
                );
            }

            // Choice questions have their document text per option only – a
            // per-question template would override the option texts.
            return ['template' => '', 'options' => $options];
        }

        // ##answer## is no known resolver token, so it survives the fill().
        return [
            'template' => $this->placeholderResolver->fill($question->getStatementTemplate(), $data, $extra, $email, $title),
            'options'  => [],
        ];
    }

    /**
     * The statement of one question for a stored value (empty value = no
     * statement). Checkbox values arrive as the ", "-joined stored string.
     *
     * @param array<string, mixed>  $data
     * @param array<string, string> $extra
     */
    public function renderStatement(QuestionModel $question, string $value, array $data, array $extra, string $email, string $title): string
    {
        if ('' === trim($value)) {
            return '';
        }

        if ($question->hasOptions()) {
            $values = $question->isMultiple() ? explode(', ', $value) : [$value];
            $parts = [];

            foreach ($values as $single) {
                $part = $this->resolveStatementText($question->getOptionStatement($single), $single, $data, $extra, $email, $title);

                if ('' !== $part) {
                    $parts[] = $part;
                }
            }

            return implode("\n", $parts);
        }

        return $this->resolveStatementText($question->getStatementTemplate(), $value, $data, $extra, $email, $title);
    }

    /**
     * Resolves the regular ##tokens## first, then substitutes ##answer## – the
     * same order as in the form (browser substitutes the live value last), so
     * a value containing "##" is never re-interpreted as a token.
     *
     * @param array<string, mixed>  $data
     * @param array<string, string> $extra
     */
    private function resolveStatementText(string $template, string $value, array $data, array $extra, string $email, string $title): string
    {
        $filled = $this->placeholderResolver->fill($template, $data, $extra, $email, $title);

        return trim(str_replace('##answer##', $value, $filled));
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
