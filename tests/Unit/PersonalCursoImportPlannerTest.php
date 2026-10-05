<?php

namespace Tests\Unit;

use App\Modules\Personal\Services\PersonalCursoImportPlanner;
use Tests\TestCase;

class PersonalCursoImportPlannerTest extends TestCase
{
    private function row(string $type, string $date): array
    {
        return ['type' => $type, 'date' => $date, 'personal_id' => 'worker', 'examen_id' => 'course',
            'mina_id' => 'mine-a', 'result' => $type === 'Resultado' ? 'APTO' : '',
            'score' => null, 'issues' => [], 'categories' => []];
    }

    private function pending(string $id, string $date, string $mineId = 'mine-a'): array
    {
        return ['id' => $id, 'scheduled' => $date, 'done' => null, 'personal_id' => 'worker',
            'examen_id' => 'course', 'mina_id' => $mineId, 'result' => 'PENDIENTE', 'score' => null];
    }

    public function test_pairs_file_rows_without_any_mine_assignment(): void
    {
        $rows = (new PersonalCursoImportPlanner)->reconcile([
            $this->row('Programacion', '2026-09-01'), $this->row('Resultado', '2026-09-01'),
        ], collect());
        $this->assertSame('Unir programacion y resultado del archivo', $rows[1]['action']);
        $this->assertSame([0], $rows[1]['linked_rows']);
        $this->assertSame([1], $rows[0]['linked_rows']);
    }

    public function test_old_result_completes_old_schedule_without_consuming_new_schedule(): void
    {
        $rows = (new PersonalCursoImportPlanner)->reconcile([
            $this->row('Programacion', '2026-09-20'), $this->row('Resultado', '2026-09-01'),
        ], collect([$this->pending('old', '2026-09-01'), $this->pending('new', '2026-09-20')]));
        $this->assertSame(['old'], $rows[1]['existing_attempt_ids']);
        $this->assertSame('Completar programacion existente', $rows[1]['action']);
        $this->assertSame(['new'], $rows[0]['existing_attempt_ids']);
        $this->assertSame([], $rows[1]['issues']);
    }

    public function test_ambiguous_pending_attempts_are_not_automatically_selected(): void
    {
        $rows = (new PersonalCursoImportPlanner)->reconcile([$this->row('Resultado', '2026-09-03')],
            collect([$this->pending('one', '2026-09-01'), $this->pending('two', '2026-09-02')]));
        $this->assertSame('Revisar conflicto de historial', $rows[0]['action']);
        $this->assertSame([], $rows[0]['existing_attempt_ids']);
    }

    public function test_only_links_the_schedule_from_the_selected_mine(): void
    {
        $rows = (new PersonalCursoImportPlanner)->reconcile([$this->row('Resultado', '2026-09-01')],
            collect([$this->pending('mine-a', '2026-09-01'), $this->pending('mine-b', '2026-09-01', 'mine-b')]));
        $this->assertSame(['mine-a'], $rows[0]['existing_attempt_ids']);
        $this->assertSame([], $rows[0]['issues']);
    }

    public function test_conflicting_final_result_is_never_overwritten(): void
    {
        $event = array_replace($this->pending('final', '2026-09-01'), ['done' => '2026-09-01', 'result' => 'DESAPROBADO']);
        $rows = (new PersonalCursoImportPlanner)->reconcile([$this->row('Resultado', '2026-09-01')], collect([$event]));
        $this->assertSame('Revisar conflicto de historial', $rows[0]['action']);
    }

    public function test_multiple_notes_cannot_consume_same_pending_attempt(): void
    {
        $rows = (new PersonalCursoImportPlanner)->reconcile([
            $this->row('Resultado', '2026-09-02'), $this->row('Resultado', '2026-09-03'),
        ], collect([$this->pending('old', '2026-09-01')]));
        $this->assertSame('Revisar conflicto de historial', $rows[0]['action']);
        $this->assertSame('Revisar conflicto de historial', $rows[1]['action']);
    }
}
