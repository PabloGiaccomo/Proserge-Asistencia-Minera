<?php

namespace App\Modules\Personal\Controllers;

use App\Http\Controllers\WebPageController;
use App\Models\Personal;
use App\Models\PersonalIngreso;
use App\Modules\Personal\Services\PersonalIngresoLinkService;
use App\Modules\Personal\Services\PersonalIngresoRevisionService;
use App\Modules\Personal\Support\PersonalIngresoPurpose;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PersonalIngresoUpdateController extends WebPageController
{
    private const SESSION_KEY = 'personal_actualizacion_links';

    public function __construct(
        private readonly PersonalIngresoRevisionService $revisions,
        private readonly PersonalIngresoLinkService $links,
    ) {}

    public function issue(Request $request, string $id): JsonResponse
    {
        $actor = $this->requireAuthenticatedUser();
        $fields = $request->validate([
            'requested_fields' => ['sometimes', 'array'],
            'requested_fields.*' => ['required', 'string', 'max:80'],
        ])['requested_fields'] ?? [];
        $personal = Personal::query()->findOrFail($id);
        $result = DB::transaction(function () use ($personal, $actor, $fields): array {
            $ingreso = $this->revisions->startUpdate($personal, $actor, PersonalIngresoPurpose::ACTUALIZACION, $fields);

            return $this->links->issue($ingreso, $actor);
        });

        return $this->json([
            'url' => $result['url'],
            'link_id' => $result['link']->id,
            'expires_at' => $result['link']->expires_at->toIso8601String(),
        ], 201);
    }

    public function revoke(string $linkId): JsonResponse
    {
        $this->links->revoke($linkId, $this->requireAuthenticatedUser());

        return $this->json(['message' => 'Enlace revocado.']);
    }

    public function publicShow(): Response
    {
        return response()->view('ficha-public.actualizacion', [], 200, $this->privateHeaders());
    }

    public function exchange(Request $request): JsonResponse
    {
        $token = $request->input('token');
        $request->offsetUnset('token');

        if ($token === null) {
            $handle = $this->sessionHandle($request);

            return $this->publicLinkResponse($this->links->resume($this->sessionLinkId($request)), $handle);
        }
        $result = $this->links->resolve(is_string($token) ? $token : '');
        abort_unless($result['link'], 403, 'Este enlace ya no está disponible.');
        $handle = bin2hex(random_bytes(32));
        $sessions = array_filter(
            $request->session()->get(self::SESSION_KEY, []),
            fn (array $session): bool => now()->timestamp <= $session['expires_at'],
        );
        $sessions[$handle] = [
            'id' => $result['link']->id,
            'expires_at' => now()->addMinutes(30)->timestamp,
        ];
        $request->session()->put(self::SESSION_KEY, $sessions);

        return $this->publicLinkResponse($result, $handle);
    }

    public function draft(Request $request): JsonResponse
    {
        $linkId = $this->sessionLinkId($request);
        try {
            $validated = $request->validate([
                'revision' => ['required', 'integer', 'min:1'], 'fields' => ['present', 'array'],
                'familiares' => ['sometimes', 'array'],
            ]);
            if (array_diff($request->keys(), ['session_handle', 'revision', 'fields', 'familiares', '_token']) !== []) {
                throw ValidationException::withMessages(['fields' => 'La solicitud solo admite los campos de actualización general.']);
            }
            $ingreso = $this->links->saveDraft($linkId, (int) $validated['revision'], $validated['fields'], $validated['familiares'] ?? null);
        } catch (ValidationException $exception) {
            return $this->validationError($exception);
        }

        return $this->json([...$this->revisions->publicDraft($ingreso), 'session_handle' => $this->sessionHandle($request)]);
    }

    public function submit(Request $request): JsonResponse
    {
        $linkId = $this->sessionLinkId($request);
        try {
            $validated = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'submission_uuid' => ['required', 'uuid']]);
            if (array_diff($request->keys(), ['session_handle', 'revision', 'submission_uuid', '_token']) !== []) {
                throw ValidationException::withMessages(['fields' => 'Envía el borrador guardado.']);
            }
            $this->links->submit($linkId, $validated['submission_uuid'], (int) $validated['revision']);
        } catch (ValidationException $exception) {
            return $this->validationError($exception);
        }

        return $this->json(['message' => 'Actualización recibida · Completa para revisión']);
    }

    public function review(string $id): View
    {
        $comparison = $this->revisions->compare($id, $this->requireAuthenticatedUser());
        $ingreso = PersonalIngreso::query()->with('personalExistente')->findOrFail($id);

        return view('personal.fichas.actualizacion-review', compact('comparison', 'ingreso'));
    }

    public function accept(Request $request, string $id): RedirectResponse
    {
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'base_hash' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/D'],
            'review_hash' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/D'],
        ]);
        $this->revisions->acceptUpdate($id, $this->requireAuthenticatedUser(), (int) $data['revision'], $data['base_hash'], $data['review_hash']);

        return redirect()->route('personal.ingresos.actualizacion.show', $id)->with('success', 'Actualización aceptada.');
    }

    public function observe(Request $request, string $id): RedirectResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:1000']]);
        $this->revisions->observeUpdate($id, $this->requireAuthenticatedUser(), (int) $data['revision'], $data['reason']);

        return redirect()->route('personal.ingresos.actualizacion.show', $id)->with('success', 'Actualización observada.');
    }

    private function sessionLinkId(Request $request): string
    {
        $sessions = $request->session()->get(self::SESSION_KEY, []);
        $session = $sessions[$this->sessionHandle($request)] ?? null;
        abort_unless($session && now()->timestamp <= $session['expires_at'], 403, 'Abre de nuevo el enlace original.');

        return $session['id'];
    }

    private function sessionHandle(Request $request): string
    {
        $handle = $request->input('session_handle');
        abort_unless(is_string($handle) && preg_match('/^[0-9a-f]{64}$/D', $handle), 403, 'Abre de nuevo el enlace original.');

        return $handle;
    }

    private function publicLinkResponse(array $result, string $handle): JsonResponse
    {
        return $result['mode'] === 'submitted'
            ? $this->json(['submitted' => true, 'message' => 'Actualización recibida · Completa para revisión'])
            : $this->json([...$result['data'], 'session_handle' => $handle]);
    }

    private function validationError(ValidationException $exception): JsonResponse
    {
        return $this->json(['message' => 'Revisa los campos de la solicitud.', 'errors' => $exception->errors()], 422);
    }

    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, $this->privateHeaders());
    }

    private function privateHeaders(): array
    {
        return ['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer'];
    }
}
