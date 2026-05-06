<?php

namespace App\Modules\Personal\Support;

class PersonalFichaCatalog
{
    public const DOCUMENT_TYPES = [
        'DNI' => 'DNI',
        'CE' => 'Carne de extranjeria',
        'PASAPORTE' => 'Pasaporte',
        'OTRO' => 'Otro',
    ];

    public const STATES = [
        'ACTIVO' => 'Activo',
        'INACTIVO' => 'Inactivo',
        'CESADO' => 'Cesado',
        'PENDIENTE_COMPLETAR_FICHA' => 'Pendiente completar ficha',
        'FICHA_ENVIADA' => 'Ficha enviada',
        'APROBADO' => 'Aprobado',
        'OBSERVADO' => 'Observado',
        'RECHAZADO' => 'Rechazado',
        'LINK_VENCIDO' => 'Link vencido',
    ];

    public static function sections(): array
    {
        return [
            [
                'key' => 'datos_personales',
                'title' => 'Datos personales',
                'fields' => [
                    self::field('nombres', 'Nombres', 'text', true),
                    self::field('apellido_paterno', 'Apellido paterno', 'text', true),
                    self::field('apellido_materno', 'Apellido materno', 'text', true),
                    self::field('sexo', 'Sexo', 'select', true, self::options(['Masculino', 'Femenino', 'Otro / Prefiere no indicar'])),
                    self::field('estado_civil', 'Estado civil', 'select', true, self::options(['Soltero', 'Casado', 'Conviviente', 'Divorciado', 'Viudo', 'Otro'])),
                    self::field('estado_civil_otro', 'Otro estado civil', 'text', false),
                    self::field('nacionalidad', 'Nacionalidad', 'select', true, self::options(['Peruana', 'Venezolana', 'Colombiana', 'Boliviana', 'Chilena', 'Argentina', 'Otra'])),
                    self::field('nacionalidad_otra', 'Otra nacionalidad', 'text', false),
                    self::field('grupo_sanguineo', 'G. sanguineo', 'select', false, self::options(['O+', 'O-', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-'])),
                    self::field('brevete', 'Brevete / licencia de conducir', 'select', false, self::options(['No tiene', 'A-I', 'A-IIa', 'A-IIb', 'A-IIIa', 'A-IIIb', 'A-IIIc', 'B-I', 'B-IIa', 'B-IIb', 'B-IIc'])),
                ],
            ],
            [
                'key' => 'documento_identidad',
                'title' => 'Documento de identidad',
                'fields' => [
                    self::field('tipo_documento', 'Tipo de documento', 'select', true, self::DOCUMENT_TYPES, ['locked_public' => true]),
                    self::field('numero_documento', 'Numero de documento', 'text', true, [], ['locked_public' => true]),
                ],
            ],
            [
                'key' => 'nacimiento',
                'title' => 'Datos de nacimiento',
                'fields' => [
                    self::field('fecha_nacimiento', 'Fecha de nacimiento', 'date', true),
                    self::field('pais_nacimiento', 'Pais de nacimiento', 'select', false, self::options(['Peru', 'Otro'])),
                    self::field('pais_nacimiento_otro', 'Pais de nacimiento', 'text', false),
                    self::field('departamento_nacimiento', 'Departamento de nacimiento', 'select', false),
                    self::field('provincia_nacimiento', 'Provincia de nacimiento', 'select', false),
                    self::field('distrito_nacimiento', 'Distrito de nacimiento', 'select', false),
                    self::field('lugar_nacimiento_extranjero', 'Lugar de nacimiento extranjero separado por /', 'text', false),
                ],
            ],
            [
                'key' => 'contacto',
                'title' => 'Datos de contacto',
                'fields' => [
                    self::field('telefono', 'Telefono celular', 'tel', true),
                    self::field('telefono_alterno', 'Telefono alterno', 'tel', false),
                    self::field('correo', 'Correo electronico', 'email', false),
                ],
            ],
            [
                'key' => 'domicilio',
                'title' => 'Domicilio actual',
                'fields' => [
                    self::field('domicilio_tipo', 'Pais de domicilio', 'select', true, self::options(['Peru', 'Extranjero'])),
                    self::field('domicilio_pais_otro', 'Pais de domicilio extranjero', 'text', false),
                    self::field('domicilio_departamento', 'Departamento', 'select', false),
                    self::field('domicilio_provincia', 'Provincia', 'select', false),
                    self::field('domicilio_distrito', 'Distrito', 'select', false),
                    self::field('domicilio_direccion', 'Direccion', 'textarea', true),
                    self::field('domicilio_referencia', 'Referencia', 'textarea', false),
                    self::field('domicilio_extranjero', 'Direccion extranjera / comuna / distrito / ciudad', 'textarea', false),
                ],
            ],
            [
                'key' => 'laboral',
                'title' => 'Datos laborales',
                'fields' => [
                    self::field('puesto', 'Cargo / puesto', 'text', true),
                    self::field('ocupacion', 'Ocupacion', 'text', false),
                    self::field('contrato', 'Tipo de contrato', 'select', true, [
                        'REG' => 'Regimen',
                        'FIJO' => 'Personal fijo / servicio especifico',
                        'INTER' => 'Intermitente',
                        'INDET' => 'Indeterminado',
                    ]),
                    self::field('fecha_ingreso', 'Inicio relacion laboral', 'date', false),
                    self::field('fecha_fin_contrato', 'Fin de contrato', 'date', false),
                    self::field('fecha_cese', 'Fecha de cese', 'date', false),
                    self::field('tipo_trabajador', 'Tipo de trabajador', 'text', false),
                    self::field('categoria_trabajador', 'Categoria', 'text', false),
                ],
            ],
            [
                'key' => 'bancarios',
                'title' => 'Datos bancarios',
                'fields' => [
                    self::field('banco', 'Banco', 'select', true, self::options(['BCP', 'Interbank', 'Otro'])),
                    self::field('banco_otro', 'Nombre de otro banco', 'text', false),
                    self::field('numero_cuenta', 'Numero de cuenta', 'text', false),
                    self::field('cci', 'CCI', 'text', false),
                ],
            ],
            [
                'key' => 'academicos',
                'title' => 'Datos academicos',
                'fields' => [
                    self::field('grado_instruccion', 'Grado de instruccion', 'select', true, self::options(['Sin estudios', 'Primaria incompleta', 'Primaria completa', 'Secundaria incompleta', 'Secundaria completa', 'Tecnico incompleto', 'Tecnico egresado', 'Tecnico titulado', 'Universitario incompleto', 'Universitario egresado', 'Bachiller', 'Titulado', 'Maestria', 'Doctorado', 'Otro'])),
                    self::field('profesion_oficio', 'Profesion u oficio', 'text', false),
                    self::field('especialidad', 'Especialidad', 'text', false),
                    self::field('anio_experiencia', 'Anos de experiencia', 'text', false),
                    self::field('anio_egreso', 'Anio de egreso', 'text', false),
                    self::field('carrera', 'Carrera', 'text', false),
                    self::field('institucion', 'Institucion', 'text', false),
                ],
            ],
            [
                'key' => 'pensionario',
                'title' => 'Sistema pensionario',
                'fields' => [
                    self::field('empleador_razon_social', 'Nombre o razon social', 'text', false),
                    self::field('empleador_ruc', 'Nro. RUC', 'text', false),
                    self::field('empleador_domicilio_fiscal', 'Departamento del domicilio fiscal', 'textarea', false),
                    self::field('remuneracion', 'Remuneracion', 'text', false),
                    self::field('sistema_pensionario', 'Eleccion del sistema pensionario', 'select', false, self::options(['ONP', 'Sistema Privado de Pensiones'])),
                    self::field('tipo_comision', 'Tipo de comision', 'text', false),
                    self::field('tipo_afp', 'AFP', 'text', false),
                    self::field('cuspp', 'CUSPP', 'text', false),
                ],
            ],
            [
                'key' => 'tallas',
                'title' => 'Tallas / EPP',
                'fields' => [
                    self::field('talla_zapato', 'Zapato / botas', 'select', false, self::options(['35', '36', '37', '38', '39', '40', '41', '42', '43', '44', '45'])),
                    self::field('talla_polo', 'Camisa / chaleco', 'select', false, self::options(['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL'])),
                    self::field('talla_pantalon', 'Pantalon', 'select', false, self::options(['28', '30', '32', '34', '36', '38', '40', '42', '44'])),
                    self::field('talla_respirador', 'Respirador', 'select', false, self::options(['S', 'M', 'L', 'XL'])),
                ],
            ],
            [
                'key' => 'quinta_categoria',
                'title' => 'Declaracion jurada de ingresos de quinta categoria',
                'fields' => [
                    self::field('quinta_ciudad', 'Ciudad', 'text', false),
                    self::field('quinta_fecha_dia', 'Dia', 'text', false),
                    self::field('quinta_fecha_mes', 'Mes', 'text', false),
                    self::field('quinta_fecha_anio', 'Anio', 'text', false),
                    self::field('quinta_ejercicio_anio', 'Ejercicio del anio', 'text', false),
                    self::field('quinta_domicilio', 'Domicilio para declaracion', 'textarea', false),
                    self::field('quinta_empleador_principal', 'Empleador principal', 'select', false, self::options(['P&S PROSERGE S.R.L.', 'Otra empresa'])),
                    self::field('quinta_otra_empresa', 'Otra empresa con mayor remuneracion', 'text', false),
                    self::field('quinta_otra_empresa_ruc', 'RUC otra empresa', 'text', false),
                    self::field('quinta_percibe_otras', 'Percibe otras remuneraciones de quinta categoria', 'select', false, self::options(['No', 'Si'])),
                    self::field('quinta_adjunta_dj_anterior', 'Adjunta DJ de anterior empleador', 'select', false, self::options(['Si', 'No aplica'])),
                    self::field('quinta_declara_sin_ingresos', 'Declara sin ingresos de cuarta/quinta en el ejercicio', 'select', false, self::options(['No', 'Si'])),
                    self::field('quinta_otros_empleadores_json', 'Otros empleadores JSON', 'hidden', false),
                    self::field('declaraciones_json', 'Declaraciones JSON', 'hidden', false),
                ],
            ],
        ];
    }

    public static function fields(): array
    {
        $fields = [];

        foreach (self::sections() as $section) {
            foreach ($section['fields'] as $field) {
                $fields[$field['key']] = [
                    ...$field,
                    'section' => $section['title'],
                ];
            }
        }

        return $fields;
    }

    public static function requiredKeys(): array
    {
        return collect(self::fields())
            ->filter(fn (array $field): bool => (bool) ($field['required'] ?? false))
            ->keys()
            ->values()
            ->all();
    }

    public static function defaultVerificationKeys(): array
    {
        return [
            'nombres',
            'apellido_paterno',
            'apellido_materno',
            'telefono',
            'correo',
            'domicilio_direccion',
            'puesto',
            'contrato',
            'fecha_ingreso',
            'banco',
            'numero_cuenta',
            'cci',
        ];
    }

    public static function emptyData(): array
    {
        return [
            ...collect(self::fields())->mapWithKeys(fn (array $field, string $key): array => [$key => ''])->all(),
            'tipo_documento' => 'DNI',
            'pais_nacimiento' => 'Peru',
            'domicilio_tipo' => 'Peru',
            'empleador_razon_social' => 'P & S Produccion y Servicios Generales S.R.L.',
            'empleador_ruc' => '20539399536',
            'empleador_domicilio_fiscal' => 'Av. Brasil, Mz. E - Lot. 3, Rio Seco Asc. Aptasa, Cerro Colorado - Arequipa',
            'quinta_ejercicio_anio' => now()->format('Y'),
        ];
    }

    public static function documentRequirements(): array
    {
        return [
            'cv_documentado' => ['label' => 'Curriculum vitae documentado (certificados de trabajo y estudios, mencionar a PROSERGE en experiencia laboral) en Word o PDF.', 'required' => true],
            'dni_vigente' => ['label' => 'Copia de DNI vigente.', 'required' => true],
            'dni_derechohabientes' => ['label' => 'Copia de DNI de derecho-habientes (hijos menores de 18 anos y esposa si corresponde).', 'required' => false],
            'matrimonio_union' => ['label' => 'Copia de partida de matrimonio o resolucion de union de hechos.', 'required' => false],
            'dni_conyuge' => ['label' => 'Copia de DNI de esposa/conviviente.', 'required' => false],
            'certificado_unico_laboral' => ['label' => 'Copia de Certificado Unico Laboral.', 'required' => true],
            'retenciones_quinta' => ['label' => 'Copia certificado de Retenciones de renta de Quinta categoria.', 'required' => true],
            'vida_ley_notarial' => ['label' => 'Declaracion Jurada Vida Ley legalizada por notario.', 'required' => true],
            'recibo_servicio' => ['label' => 'Copia de recibo de agua o luz.', 'required' => true],
            'carnet_vacunacion' => ['label' => 'Carnet de vacunacion 3 dosis completo descriptivo.', 'required' => true],
            'foto_carnet' => ['label' => 'Foto tamano carnet JPG: 640x480 px, fondo blanco, reciente, mirada frontal, sin accesorios.', 'required' => true],
        ];
    }

    public static function declarationCheckboxes(): array
    {
        return [
            'datos_verdaderos' => 'Declaro bajo juramento que los datos consignados son verdaderos.',
            'firma_huella_unica' => 'Confirmo que los datos consignados seran usados para validar mi unica firma digital y mi huella digital en este formulario.',
            'quinta_responsabilidad' => 'Expido el presente documento con caracter de declaracion jurada. Declaro que su contenido se ajusta a la verdad y, si existe alguna variacion, me comprometo a actualizar la informacion a mas tardar el 30 de noviembre del anio en curso, bajo mi responsabilidad.',
            'domicilio_real' => 'Declaro bajo juramento que la direccion senalada es mi domicilio real, actual, efectivo y verdadero, donde tengo vivencia real, fisica y permanente, para fines legales de trabajo.',
        ];
    }

    public static function stateLabel(?string $state): string
    {
        $state = strtoupper(trim((string) $state));

        return self::STATES[$state] ?? ($state !== '' ? str_replace('_', ' ', $state) : '-');
    }

    private static function field(string $key, string $label, string $type, bool $required, array $options = [], array $extra = []): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'type' => $type,
            'required' => $required,
            'options' => $options,
            ...$extra,
        ];
    }

    private static function options(array $values): array
    {
        return collect($values)->mapWithKeys(fn (string $value): array => [$value => $value])->all();
    }
}
