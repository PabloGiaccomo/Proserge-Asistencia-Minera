<?php

namespace App\Modules\Personal\Services;

use App\Models\PersonalIngreso;
use App\Models\PersonalIngresoLink;
use App\Models\Usuario;
use App\Modules\Personal\Support\PersonalIngresoPurpose;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PersonalIngresoLinkService
{
    public const VALID_HOURS = 72;

    public function __construct(private readonly PersonalIngresoRevisionService $revisions) {}

    public function issue(PersonalIngreso $ingreso, Usuario $actor): array
    {
        $this->revisions->assertCanIssue($actor);

        return DB::transaction(function () use ($ingreso, $actor): array {
            $ingreso = PersonalIngreso::query()->lockForUpdate()->findOrFail($ingreso->id);
            $this->revisions->assertCanIssue($actor, currentRead: true);
            $this->assertDraft($ingreso);
            PersonalIngresoLink::query()->where('ingreso_id', $ingreso->id)
                ->whereNull('revoked_at')->whereNull('submitted_at')
                ->update(['revoked_at' => now(), 'revoked_by_actor_id' => $actor->id]);

            $token = bin2hex(random_bytes(32));
            $link = PersonalIngresoLink::query()->create([
                'id' => (string) Str::uuid(),
                'ingreso_id' => $ingreso->id,
                'personal_id' => $ingreso->personal_existente_id,
                'proposito' => $ingreso->proposito,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addHours(self::VALID_HOURS),
                'actor_id' => $actor->id,
            ]);

            return ['link' => $link, 'url' => route('personal.actualizacion.public.show').'#'.$token];
        });
    }

    public function revoke(string $linkId, Usuario $actor): void
    {
        $this->revisions->assertCanIssue($actor);
        PersonalIngresoLink::query()->whereKey($linkId)->whereNull('revoked_at')->update([
            'revoked_at' => now(), 'revoked_by_actor_id' => $actor->id,
        ]);
    }

    public function resolve(string $token): array
    {
        if (! preg_match('/^[0-9a-f]{64}$/D', $token)) {
            return ['mode' => 'unavailable', 'link' => null, 'data' => []];
        }

        $link = PersonalIngresoLink::query()->where('token_hash', hash('sha256', $token))->first();
        if (! $link || ! $this->isValid($link)) {
            return ['mode' => 'unavailable', 'link' => null, 'data' => []];
        }

        return ['link' => $link, ...$this->resume($link->id)];
    }

    public function resume(string $linkId): array
    {
        return $this->withLockedLink($linkId, function (PersonalIngresoLink $link, PersonalIngreso $ingreso): array {
            if ($link->submitted_at) {
                return ['mode' => 'submitted', 'data' => []];
            }

            $this->assertDraft($ingreso);

            return ['mode' => 'edit', 'data' => $this->revisions->publicDraft($ingreso)];
        });
    }

    public function saveDraft(string $linkId, int $expectedRevision, array $fields, ?array $familiares = null): PersonalIngreso
    {
        return $this->withLockedLink($linkId, function (PersonalIngresoLink $link, PersonalIngreso $ingreso) use ($expectedRevision, $fields, $familiares): PersonalIngreso {
            abort_if($link->submitted_at, 403);
            $this->assertDraft($ingreso);

            return $this->revisions->saveDraft($ingreso, $expectedRevision, $fields, $familiares);
        });
    }

    public function submit(string $linkId, string $submissionUuid, int $expectedRevision): PersonalIngreso
    {
        try {
            return $this->withLockedLink($linkId, function (PersonalIngresoLink $link, PersonalIngreso $ingreso) use ($submissionUuid, $expectedRevision): PersonalIngreso {
                if ($link->submitted_at) {
                    abort_unless($ingreso->submission_uuid === $submissionUuid && $ingreso->revision === $expectedRevision, 409);

                    return $ingreso;
                }

                $this->assertDraft($ingreso);
                $this->revisions->submit($ingreso, $expectedRevision, $submissionUuid);
                $link->forceFill(['submitted_at' => now()])->save();

                return $ingreso;
            });
        } catch (UniqueConstraintViolationException) {
            abort(409, 'El envío ya está registrado. Vuelve a abrir la solicitud.');
        }
    }

    private function withLockedLink(string $linkId, callable $operation): mixed
    {
        return DB::transaction(function () use ($linkId, $operation): mixed {
            $reference = PersonalIngresoLink::query()->find($linkId);
            abort_unless($reference, 403);
            $ingreso = PersonalIngreso::query()->lockForUpdate()->find($reference->ingreso_id);
            $link = PersonalIngresoLink::query()->lockForUpdate()->find($linkId);
            abort_unless($link && $ingreso && $this->isValid($link)
                && $link->personal_id === $ingreso->personal_existente_id
                && $link->proposito === $ingreso->proposito, 403, 'Este enlace ya no está disponible.');

            return $operation($link, $ingreso);
        }, 3);
    }

    private function assertDraft(PersonalIngreso $ingreso): void
    {
        abort_unless($ingreso->proposito === PersonalIngresoPurpose::ACTUALIZACION
            && $ingreso->estado === PersonalIngreso::ESTADO_BORRADOR
            && $ingreso->personal_existente_id, 403);
    }

    private function isValid(PersonalIngresoLink $link): bool
    {
        return ! $link->revoked_at
            && $link->proposito === PersonalIngresoPurpose::ACTUALIZACION
            && now()->lessThanOrEqualTo($link->expires_at);
    }
}
