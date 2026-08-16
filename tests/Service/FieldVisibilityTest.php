<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Psimandl\WorkflowBundle\Excel\ValueParser;
use Psimandl\WorkflowBundle\Model\QuestionModel;
use Psimandl\WorkflowBundle\Service\ConditionMatcher;
use Psimandl\WorkflowBundle\Service\FieldVisibility;

/**
 * Visibility of conditional form fields. This decides three things at once – whether the field
 * is rendered, whether its answer is validated and stored, and whether its statement reaches
 * the document – so every case here has a consequence in the participant's PDF.
 */
final class FieldVisibilityTest extends TestCase
{
    private FieldVisibility $visibility;

    protected function setUp(): void
    {
        $this->visibility = new FieldVisibility(new ConditionMatcher(new ValueParser()));
    }

    /**
     * Builds a question with only the fields the visibility cares about. The real model
     * methods run (only the data access is stubbed), so the deserialisation and the defaults
     * are covered as well.
     *
     * @param array<int, array{field: string, operator: string, value?: string}> $conditions
     */
    private function question(int $id, string $storage = '', string $mode = '', array $conditions = [], string $logic = 'and'): QuestionModel
    {
        $values = [
            'id'             => $id,
            'storageField'   => $storage,
            'conditionMode'  => $mode,
            'conditionLogic' => $logic,
            'conditions'     => serialize($conditions),
        ];

        $question = $this->getMockBuilder(QuestionModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['__get'])
            ->getMock()
        ;

        $question->method('__get')->willReturnCallback(
            static fn (string $key): mixed => $values[$key] ?? '',
        );

        return $question;
    }

    public function testFieldWithoutConditionIsVisible(): void
    {
        $this->assertTrue($this->visibility->isVisible($this->question(1), []));
    }

    public function testShowModeRevealsOnMatchAndHidesOtherwise(): void
    {
        $question = $this->question(2, 'Anzahl_Kinder', 'show', [
            ['field' => 'Kinder', 'operator' => 'eq', 'value' => 'ja'],
        ]);

        $this->assertTrue($this->visibility->isVisible($question, ['Kinder' => 'ja']));
        $this->assertFalse($this->visibility->isVisible($question, ['Kinder' => 'nein']));
        $this->assertFalse($this->visibility->isVisible($question, []));
    }

    public function testHideModeIsTheNegation(): void
    {
        $question = $this->question(3, 'Grund', 'hide', [
            ['field' => 'Kinder', 'operator' => 'eq', 'value' => 'ja'],
        ]);

        $this->assertFalse($this->visibility->isVisible($question, ['Kinder' => 'ja']));
        $this->assertTrue($this->visibility->isVisible($question, ['Kinder' => 'nein']));
    }

    public function testAndRequiresEveryConditionOrRequiresOne(): void
    {
        $conditions = [
            ['field' => 'Kinder', 'operator' => 'eq', 'value' => 'ja'],
            ['field' => 'Status', 'operator' => 'eq', 'value' => 'aktiv'],
        ];

        $and = $this->question(4, 'X', 'show', $conditions);
        $or = $this->question(5, 'X', 'show', $conditions, 'or');

        $this->assertTrue($this->visibility->isVisible($and, ['Kinder' => 'ja', 'Status' => 'aktiv']));
        $this->assertFalse($this->visibility->isVisible($and, ['Kinder' => 'ja', 'Status' => 'ruht']));

        $this->assertTrue($this->visibility->isVisible($or, ['Kinder' => 'ja', 'Status' => 'ruht']));
        $this->assertFalse($this->visibility->isVisible($or, ['Kinder' => 'nein', 'Status' => 'ruht']));
    }

    /**
     * Checkbox answers are stored as a ", "-joined list, so "enthält" is the "this option is
     * ticked" operator – the wording the field tooltip promises.
     */
    public function testCheckboxTriggerViaContains(): void
    {
        $question = $this->question(6, 'X', 'show', [
            ['field' => 'Verkehrsmittel', 'operator' => 'contains', 'value' => 'Rad'],
        ]);

        $this->assertTrue($this->visibility->isVisible($question, ['Verkehrsmittel' => 'Bahn, Rad']));
        $this->assertFalse($this->visibility->isVisible($question, ['Verkehrsmittel' => 'Bahn, Auto']));
    }

    /**
     * A mode without a usable condition must NOT hide the field. A field that silently
     * disappears is the failure nobody notices; the save callback refuses this state, and this
     * is the runtime safety net behind it.
     */
    public function testIncompleteConfigurationStaysVisible(): void
    {
        $noConditions = $this->question(7, 'X', 'show');
        $incompleteRow = $this->question(8, 'X', 'show', [['field' => 'Kinder', 'operator' => '']]);

        $this->assertTrue($this->visibility->isVisible($noConditions, []));
        $this->assertTrue($this->visibility->isVisible($incompleteRow, ['Kinder' => 'nein']));
    }

    /**
     * The cascade: a hidden field's value counts as empty for everything after it. Without
     * this, a chain would come apart at its first hidden link – here the second field would
     * stay visible on a value the participant was never shown.
     */
    public function testHiddenFieldCountsAsEmptyForFollowingConditions(): void
    {
        $questions = [
            $this->question(1, 'Kinder'),
            $this->question(2, 'Anzahl_Kinder', 'show', [['field' => 'Kinder', 'operator' => 'eq', 'value' => 'ja']]),
            $this->question(3, 'Namen', 'show', [['field' => 'Anzahl_Kinder', 'operator' => 'notempty', 'value' => '']]),
        ];

        // "nein" hides field 2; its stale value must not keep field 3 alive.
        $visible = $this->visibility->resolve($questions, ['Kinder' => 'nein', 'Anzahl_Kinder' => '3']);

        $this->assertSame([1 => true, 2 => false, 3 => false], $visible);

        // With "ja" the whole chain is visible again.
        $visible = $this->visibility->resolve($questions, ['Kinder' => 'ja', 'Anzahl_Kinder' => '3']);

        $this->assertSame([1 => true, 2 => true, 3 => true], $visible);
    }
}
