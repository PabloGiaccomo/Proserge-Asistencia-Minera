# Contexto general para Codex

Ultima auditoria: 2026-06-10.

## Naturaleza del sistema

El sistema Proserge no es solo RR.HH. Es una aplicacion corporativa interna que
conecta procesos de RR.HH., reclutamiento, operaciones, logistica, contabilidad,
administracion, costos, SSOMA, gerencia y futuras areas. El sistema ya opera
con informacion real y debe tratar cada cambio como potencialmente sensible.

Los usuarios incluyen personal de oficina, supervisores y trabajadores con poca
familiaridad con sistemas. La experiencia debe ser clara, rapida y cercana al
lenguaje que ya usan en Excel, reportes y formatos manuales.

## Stack actual

- Backend: Laravel 12, PHP 8.2.
- Base de datos: MySQL.
- Frontend: Blade renderizado por servidor, JavaScript nativo, CSS propio,
  Vite/Tailwind como build global.
- Archivos: disco privado `local` en `storage/app/private`.
- Excel: PhpSpreadsheet.
- PDF: Dompdf, FPDF, FPDI.
- Autenticacion web: sesion propia con `web.auth`.
- API: tokens Bearer propios con middleware `auth.token`.
- Permisos: matriz modulo/accion en `app/Support/Rbac`.

## Flujos centrales existentes

Personal es el modulo mas avanzado. Incluye:

- registro manual de trabajador;
- links temporales de ficha publica;
- carga de documentos, firma y huella;
- revision, observacion y aprobacion de ficha;
- estados documentales;
- descarga individual y masiva de documentos;
- datos de contrato y contrato firmado;
- contratos laborales con historial inamovible;
- personal antiguo nuevo y regularizacion de personal antiguo existente;
- renovacion individual y reingreso;
- vencimientos, decision de renovacion/no renovacion;
- cierre manual de no renovado y cese controlado;
- habilitacion minera base, examenes, intentos, convalidaciones e importacion
  desde master Excel.

Otros flujos conectados:

- RQ Mina: paradas, areas, plan operativo, transporte y autocompletados.
- Herramientas por parada: listas semanales por grupos.
- RQ Proserge: requerimientos de personal y asignacion.
- Man Power: grupos de trabajo por parada y destino.
- Asistencia y faltas: marcacion, cierre, reapertura y correcciones.
- Evaluaciones: desempeno, supervisor y promedios.
- Bienestar: bloqueos, gestacion, restricciones, descanso medico.
- Seguridad: usuarios, roles, permisos, scope por mina y notificaciones.

## Trabajadores antiguos y nuevos

Trabajadores antiguos:

- pueden ya existir o registrarse desde cero;
- pueden estar activos, cesados, con datos incompletos o pendiente de
  regularizacion;
- no deben duplicarse por DNI/documento;
- deben conservar documentos, contratos, motivos de cese, origen de registro e
  historial;
- deben poder ampliar su historial sin rehacer estructuras.

Trabajadores nuevos:

- pueden iniciar como postulantes o personal en proceso;
- completan ficha, documentos, revision y contrato;
- no deben quedar `ACTIVO` sin contrato firmado vigente;
- pueden iniciar habilitacion minera durante el proceso si la operacion lo
  permite, pero eso no debe activar laboralmente al trabajador.

## Reglas ya definidas

- Estado laboral y contrato son responsabilidades del modulo Personal/Contratos.
- Habilitacion minera solo gestiona trabajador-mina-examenes-intentos.
- Documentos tienen estado propio y pueden estar pendientes, cargados,
  observados, aprobados o no aplicar.
- Contratos antiguos y cerrados son solo lectura.
- Renovaciones nunca sobreescriben el contrato anterior.
- Excel historicos pueden servir de referencia, pero no siempre son fuente de
  verdad.
- Las importaciones deben decir que crearon, actualizaron, omitieron o fallaron.

## Adopcion y UX

La aplicacion debe ayudar a migrar desde Excel sin imponer lenguaje tecnico.
Tablas, filtros y reportes deben ser escaneables. Los formularios largos deben
dividirse o esconderse detras de acciones claras. Las pantallas deben indicar el
siguiente paso esperado: revisar ficha, falta contrato, subir firmado, decidir
renovacion, cerrar contrato, asignar mina, registrar examen, etc.

## Crecimiento y archivo historico

Se espera crecimiento en:

- documentos personales;
- contratos firmados;
- archivos de examenes;
- masters Excel;
- fichas y PDFs;
- historial laboral y de procesos;
- asistencia, faltas y evaluaciones.

Debe planearse separacion logica entre activo, historico y archivado. El
archivado debe mantener referencia en MySQL, permisos, auditoria, fecha de
movimiento, origen, responsable y mecanismo de recuperacion.
