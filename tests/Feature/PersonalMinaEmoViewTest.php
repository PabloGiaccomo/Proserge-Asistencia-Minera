<?php

namespace Tests\Feature;

use App\Modules\Personal\Support\EmoOutcome;
use App\Modules\Personal\Support\ScheduledControlFilters;
use App\Modules\Personal\Support\ScheduledControlRow;
use App\Support\Rbac\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class PersonalMinaEmoViewTest extends TestCase
{
    public function test_control_renders_service_totals_id_filters_and_export_query(): void
    {
        $html = $this->renderControl(['ver_programados', 'exportar']);
        $this->assertStringContainsString('137 registros', $html);
        $this->assertStringContainsString('name="scheduled_from"', $html);
        $this->assertStringContainsString('value="00000000-0000-4000-8000-000000000001" selected', $html);
        $this->assertStringContainsString('scheduled_clinic_id=00000000-0000-4000-8000-000000000002', $html);
        $this->assertStringContainsString('Exportar resultados', $html);
        $this->assertStringContainsString('Historico sin clinica', $html);
        $this->assertStringContainsString('Apto con restriccion', $html);
    }

    public function test_emo_outcomes_use_habilitating_labels_colors_and_accessible_detail_tooltips(): void
    {
        $html = $this->renderControl(['ver_programados']);
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($document);

        foreach ([
            'APTO' => ['ok', 'Apto', ''],
            'NO_APTO' => ['danger', 'No apto', ''],
            'APTO_CON_RESTRICCION' => ['warn', 'Apto con restriccion', 'Restriccion: Evitar alturas'],
            'APTO_CON_OBSERVACION' => ['orange', 'Apto con observacion', 'Observacion: Control anual'],
        ] as $outcome => [$color, $label, $detail]) {
            $badge = $xpath->query(sprintf('//*[@data-emo-outcome="%s"]', $outcome))->item(0);

            $this->assertNotNull($badge, $outcome);
            $this->assertStringContainsString($color, $badge->getAttribute('class'), $outcome);
            $this->assertSame($label, trim($badge->textContent), $outcome);
            $this->assertSame($detail, $badge->getAttribute('title'), $outcome);
            $this->assertSame($detail, $badge->getAttribute('data-emo-tooltip'), $outcome);
            $this->assertSame($detail === '' ? $label : $label.'. '.$detail, $badge->getAttribute('aria-label'), $outcome);
            $this->assertSame($detail === '' ? '' : '0', $badge->getAttribute('tabindex'), $outcome);
            if ($detail !== '') {
                $this->assertStringNotContainsString(substr($detail, strpos($detail, ': ') + 2), $badge->parentNode->textContent, $outcome.' does not show medical detail inline');
            }
        }
    }

    public function test_legacy_medical_outcome_without_detail_exposes_an_explicit_accessible_fallback(): void
    {
        $html = $this->renderControl(['ver_programados']);
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($document);

        foreach ([
            'outcome-restriccion-empty' => 'Detalle de restriccion no registrado',
            'outcome-observacion-empty' => 'Detalle de observacion no registrado',
        ] as $rowKey => $fallback) {
            $badge = $xpath->query(sprintf('//tr[@data-control-row="%s"]//*[@data-emo-outcome]', $rowKey))->item(0);
            $this->assertNotNull($badge);
            $this->assertSame($fallback, $badge->getAttribute('title'));
            $this->assertSame($fallback, $badge->getAttribute('data-emo-tooltip'));
            $this->assertSame('0', $badge->getAttribute('tabindex'));
        }
    }

    public function test_export_requires_both_direct_permissions(): void
    {
        foreach ([['ver_programados'], ['exportar'], ['editar']] as $permissions) {
            $this->assertStringNotContainsString('Exportar resultados', $this->renderControl($permissions));
        }
    }

    public function test_appointment_actions_carry_real_revision_and_respect_permissions(): void
    {
        $html = $this->renderControl(['ver_programados', 'programar', 'registrar']);
        $this->assertStringContainsString('data-emo-revision="7"', $html);
        $this->assertStringContainsString('data-emo-action="complete"', $html);
        $this->assertStringContainsString('data-emo-action="regularize"', $html);
        $this->assertStringNotContainsString('data-emo-action="complete"', $this->renderControl(['ver_programados']));
    }

    public function test_completed_legacy_exams_cannot_offer_clinic_regularization(): void
    {
        $html = $this->renderControl(['ver_programados', 'programar'], 'ASISTIO');
        $this->assertStringNotContainsString('data-emo-action="regularize"', $html);
    }

    public function test_control_actions_follow_appointment_state(): void
    {
        foreach ([
            'PROGRAMADA' => ['complete', 'cancel'],
            'ANULADA' => ['reprogram'],
            'COMPLETADA' => [],
        ] as $state => $expectedActions) {
            $html = $this->renderControl(['ver_programados', 'programar', 'registrar'], 'ASISTIO', $state);
            $document = new \DOMDocument;
            @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
            $xpath = new \DOMXPath($document);
            $buttons = $xpath->query('//tr[@data-control-row="appointment-fixture"]//button[@data-emo-action]');
            $actions = [];
            foreach ($buttons as $button) {
                $actions[] = $button->getAttribute('data-emo-action');
                $this->assertSame('7', $button->getAttribute('data-emo-revision'));
            }
            $this->assertSame($expectedActions, $actions, $state);
            if ($state === 'PROGRAMADA') {
                $this->assertStringContainsString('Anule la cita para reprogramarla.', $html);
            }
        }
    }

    public function test_dialog_runtime_keeps_input_and_conflict_without_allowing_script_injection(): void
    {
        session()->put('_old_input', ['clinica_id' => 'clinic-fixture', 'fecha_programacion' => '2026-10-09', 'observacion' => '</script><script>alert(1)</script>']);
        session()->put('habilitacion_mina_emo_modal', 'schedule');
        session()->put('habilitacion_mina_emo_context', ['workerExamId' => 'worker-exam-fixture']);
        $html = view('personal.habilitacion-minera._emo-dialogs', ['emoClinics' => collect(), 'errors' => new ViewErrorBag])->render();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        preg_match('/id="miningEmoRuntime">(.*?)<\/script>/s', $html, $matches);
        $data = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('clinic-fixture', $data['old']['clinica_id']);
        $this->assertSame('2026-10-09', $data['old']['fecha_programacion']);
        $this->assertSame('schedule', $data['reopen']);
        $this->assertSame('worker-exam-fixture', $data['context']['workerExamId']);
    }

    private function renderControl(array $actions, string $legacyState = 'PROGRAMADO', string $appointmentState = 'PROGRAMADA'): string
    {
        $this->assertTrue(view()->exists('personal.habilitacion-minera._scheduled-control'), 'The service-backed attendance control is available to render.');
        session()->put('user.permissions', PermissionCatalog::matrixFromSelections(['habilitacion_minera' => $actions]));
        $day = CarbonImmutable::parse('2026-10-08');
        $filters = ScheduledControlFilters::fromValidated([
            'scheduled_exam_id' => '00000000-0000-4000-8000-000000000001',
            'scheduled_clinic_id' => '00000000-0000-4000-8000-000000000002',
        ], $day);
        $rows = [
            new ScheduledControlRow('appointment-fixture', 'EMO', $day, 'clinic-fixture', 'Clinica ficticia', 7, 'worker-fixture', '00000001', 'Trabajador ficticio', 'Tecnico', 'exam-fixture', 'EMO ficticio', ['mine-a', 'mine-b'], ['Mina ficticia A', 'Mina ficticia B'], ['Intento 1', 'Intento 2'], 'PROGRAMADO', null, null, null, $appointmentState),
            new ScheduledControlRow('legacy-emo-attempt:attempt-fixture', 'EMO', $day, null, 'Historico sin clinica', null, 'worker-fixture', '00000001', 'Trabajador ficticio', null, 'exam-fixture', 'EMO historico', ['mine-a'], ['Mina ficticia A'], ['Intento 1'], $legacyState, null, null, null, 'APROBADO'),
            new ScheduledControlRow('outcome-apto', 'EMO', $day, 'clinic-fixture', 'Clinica ficticia', 1, 'worker-fixture', '00000001', 'Trabajador ficticio', null, 'exam-fixture', 'EMO apto', ['mine-a'], ['Mina ficticia A'], ['Intento 1'], 'ASISTIO', EmoOutcome::APTO, null, $day, 'COMPLETADA'),
            new ScheduledControlRow('outcome-no-apto', 'EMO', $day, 'clinic-fixture', 'Clinica ficticia', 1, 'worker-fixture', '00000001', 'Trabajador ficticio', null, 'exam-fixture', 'EMO no apto', ['mine-a'], ['Mina ficticia A'], ['Intento 1'], 'ASISTIO', EmoOutcome::NO_APTO, null, $day, 'COMPLETADA'),
            new ScheduledControlRow('outcome-restriccion', 'EMO', $day, 'clinic-fixture', 'Clinica ficticia', 1, 'worker-fixture', '00000001', 'Trabajador ficticio', null, 'exam-fixture', 'EMO restringido', ['mine-a'], ['Mina ficticia A'], ['Intento 1'], 'ASISTIO', EmoOutcome::APTO_CON_RESTRICCION, 'Evitar alturas', $day, 'COMPLETADA'),
            new ScheduledControlRow('outcome-observacion', 'EMO', $day, 'clinic-fixture', 'Clinica ficticia', 1, 'worker-fixture', '00000001', 'Trabajador ficticio', null, 'exam-fixture', 'EMO observado', ['mine-a'], ['Mina ficticia A'], ['Intento 1'], 'ASISTIO', EmoOutcome::APTO_CON_OBSERVACION, 'Control anual', $day, 'COMPLETADA'),
            new ScheduledControlRow('outcome-restriccion-empty', 'EMO', $day, 'clinic-fixture', 'Clinica ficticia', 1, 'worker-fixture', '00000001', 'Trabajador ficticio', null, 'exam-fixture', 'EMO restringido sin detalle', ['mine-a'], ['Mina ficticia A'], ['Intento 1'], 'ASISTIO', EmoOutcome::APTO_CON_RESTRICCION, null, $day, 'COMPLETADA'),
            new ScheduledControlRow('outcome-observacion-empty', 'EMO', $day, 'clinic-fixture', 'Clinica ficticia', 1, 'worker-fixture', '00000001', 'Trabajador ficticio', null, 'exam-fixture', 'EMO observado sin detalle', ['mine-a'], ['Mina ficticia A'], ['Intento 1'], 'ASISTIO', EmoOutcome::APTO_CON_OBSERVACION, null, $day, 'COMPLETADA'),
        ];

        return view('personal.habilitacion-minera._scheduled-control', [
            'activeMineView' => 'scheduled', 'scheduledControlFilters' => $filters,
            'scheduledControl' => new LengthAwarePaginator($rows, 137, 25, 1, ['path' => '/personal/habilitacion-minera']),
            'scheduledControlOptions' => ['exams' => [['id' => $filters->examId, 'name' => 'EMO ficticio']], 'clinics' => [['id' => $filters->clinicId, 'name' => 'Clinica ficticia']]],
        ])->render();
    }
}
