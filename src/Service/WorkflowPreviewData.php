<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

use Psimandl\WorkflowBundle\Model\EntryModel;
use Psimandl\WorkflowBundle\Model\QuestionModel;
use Psimandl\WorkflowBundle\Model\WorkflowModel;

/**
 * Produces a representative, NOT persisted sample entry for the back-end PDF /
 * form previews: the most recent real entry when one exists (realistic names and
 * values), otherwise a synthetic one seeded from the source columns. Either way
 * every answer field is filled with a representative value so the previewed
 * document and form show a complete result instead of empty placeholders.
 */
class WorkflowPreviewData
{
    public function __construct(
        private readonly SpreadsheetInspector $inspector,
    ) {
    }

    public function sampleEntry(WorkflowModel $workflow): EntryModel
    {
        $latest = $this->latestEntry($workflow);
        $data = null !== $latest ? $latest->getData() : [];

        // No real entry yet: seed the source columns from the headers so their
        // ##data_*## placeholders resolve to a (clearly labelled) sample value.
        if ([] === $data) {
            foreach ($this->inspector->getHeaders($workflow) as $name) {
                $data[$name] = $name;
            }
        }

        // Make sure every answer field carries a value, so the statements (and
        // thus the rule-based letter body) render fully in the preview.
        foreach ($workflow->getQuestions() as $question) {
            $storage = trim((string) $question->storageField);

            if ('' !== $storage && '' === trim((string) ($data[$storage] ?? ''))) {
                $data[$storage] = $this->sampleAnswer($question);
            }
        }

        $entry = new EntryModel();
        $entry->pid = (int) $workflow->id;
        $entry->tstamp = time();
        $entry->token = 'preview';
        $entry->status = WorkflowStatus::STATUS_IMPORTED;
        $entry->email = null !== $latest && '' !== (string) $latest->email ? (string) $latest->email : 'beispiel@example.org';
        $entry->data = serialize($data);
        $entry->signature = '';

        return $entry;
    }

    private function latestEntry(WorkflowModel $workflow): ?EntryModel
    {
        $entries = EntryModel::findBy('pid', (int) $workflow->id, ['order' => 'tstamp DESC', 'limit' => 1]);

        return null !== $entries ? $entries->first()->current() : null;
    }

    private function sampleAnswer(QuestionModel $question): string
    {
        if ($question->isCurrentTime() || 'date' === (string) $question->type) {
            return date('d.m.Y');
        }

        if ($question->hasOptions()) {
            $options = $question->getOptions();

            return [] !== $options ? $options[0]['value'] : '';
        }

        if ('number' === (string) $question->type) {
            return '100';
        }

        return 'Beispiel';
    }
}
