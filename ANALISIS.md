# Análisis del Plugin `local_downloadcenter`

## Índice

1. [Ficha técnica](#1-ficha-técnica)
2. [Estructura de archivos](#2-estructura-de-archivos)
3. [Archivo por archivo](#3-archivo-por-archivo)
   - [version.php](#31-versionphp)
   - [db/access.php](#32-dbaccessphp)
   - [lib.php](#33-libphp)
   - [index.php](#34-indexphp)
   - [locallib.php](#35-locallibphp)
   - [download_form.php](#36-download_formphp)
   - [styles.css](#37-stylescss)
   - [classes/event/](#38-classesevent)
   - [classes/privacy/provider.php](#39-classesprivacyproviderphp)
   - [lang/en/local_downloadcenter.php](#310-langenlocal_downloadcenterphp)
   - [templates/searchbox.mustache](#311-templatessearchboxmustache)
   - [amd/src/](#312-amdsrc)
   - [tests/](#313-tests)
4. [Cómo funciona el flujo completo](#4-cómo-funciona-el-flujo-completo)
5. [Sistema de permisos](#5-sistema-de-permisos)
6. [Lo que hace bien (para aprender)](#6-lo-que-hace-bien-para-aprender)
7. [Lo que hace distinto a tu plugin de práctica](#7-lo-que-hace-distinto-a-tu-plugin-de-práctica)

---

## 1. Ficha técnica

| Dato | Valor |
|------|-------|
| **Componente** | `local_downloadcenter` |
| **Versión** | 2025100600 |
| **Requiere Moodle** | 2025100600 (Moodle 5.1) |
| **Madurez** | `MATURITY_STABLE` |
| **Release** | v5.1.0 |
| **Autor** | Simeon Naydenov, Clemens Marx |
| **Organización** | Academic Moodle Cooperation |
| **Propósito** | Descargar actividades/recursos de un curso como ZIP |
| **¿Usa DB?** | No (sin tablas propias) |
| **¿Usa hooks?** | No (usa callbacks antiguos) |

---

## 2. Estructura de archivos

```
downloadcenter/
├── version.php                       Versión y metadatos
├── lib.php                           Callbacks de navegación (antiguo sistema)
├── index.php                         Página principal del plugin
├── locallib.php                      ★ Lógica principal (1344 líneas)
├── download_form.php                 Formulario de selección de recursos
├── styles.css                        Estilos CSS (205 líneas)
├── db/
│   └── access.php                    ★ Capacidades/permisos
├── lang/
│   └── en/
│       └── local_downloadcenter.php  ★ Cadenas de idioma
├── classes/
│   ├── event/
│   │   ├── plugin_viewed.php         Evento: plugin visto
│   │   └── zip_downloaded.php        Evento: ZIP descargado
│   └── privacy/
│       └── provider.php              ★ Proveedor de privacidad GDPR
├── templates/
│   └── searchbox.mustache            Template del buscador
├── amd/
│   ├── src/
│   │   ├── modfilter.js              ★ JS para filtros (seleccionar todo/ninguno)
│   │   └── search.js                 ★ JS para búsqueda en vivo
│   └── build/                        Versiones minificadas
│       ├── modfilter.min.js
│       ├── modfilter.min.js.map
│       ├── search.min.js
│       └── search.min.js.map
├── pix/
│   ├── icon.png                      Icono PNG
│   ├── icon.svg                      Icono SVG
│   └── icon_white.svg                Icono SVG blanco
├── tests/
│   ├── files_visible_test.php        ★ Tests PHPUnit de visibilidad
│   ├── locallib_test.php             ★ Tests PHPUnit de lógica interna
│   └── behat/
│       └── check_activities.feature  ★ Tests Behat funcionales
├── README.md                         Documentación
├── CHANGELOG.md                      Historial de cambios
└── .gitlab-ci.yml                    CI/CD GitLab
```

---

## 3. Archivo por archivo

### 3.1 version.php

```php
$plugin->version   = 2025100600;
$plugin->requires  = 2025100600;
$plugin->component = 'local_downloadcenter';
$plugin->maturity  = MATURITY_STABLE;
$plugin->release   = "v5.1.0";
```

- **`version`**: Sigue el estándar `YYYYMMDDXX`. Este plugin usa `2025100600` (6 Oct 2025, release 00).
- **`requires`**: `2025100600` = versión de Moodle 5.1.0. Coincide con el version ID de Moodle 5.1 (sin decimal).
- **`component`**: `local_downloadcenter` → por lo tanto el directorio debe ser `local/downloadcenter/`.
- **`maturity`**: `MATURITY_STABLE` → listo para producción.
- **`release`**: `v5.1.0` → versión legible, alineada con la versión de Moodle que soporta.

### 3.2 db/access.php

```php
$capabilities = [
    'local/downloadcenter:view' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'student' => CAP_ALLOW,
            'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],
];
```

- Define **una sola capacidad**: `local/downloadcenter:view`.
- Se otorga a **todos** los roles: student, teacher, editingteacher, manager.
- Se evalúa a nivel de **curso** (`CONTEXT_COURSE`).
- Es una capacidad de tipo **lectura** (`captype => 'read'`).

### 3.3 lib.php

Usa el **sistema antiguo de callbacks** (no hooks). Dos funciones:

```php
// 1. Añade enlace en la navegación del curso
function local_downloadcenter_extend_navigation_course(
    navigation_node $parentnode, stdClass $course, context_course $context
) {
    if (!has_capability('local/downloadcenter:view', $context)) {
        return; // No mostrar si no tiene permiso
    }
    // Busca posición adecuada para el enlace
    $keys = ['questionbank', 'unenrollself', 'filtermanagement'];
    $beforekey = ...;
    
    // Crea el enlace con icono
    $url = new moodle_url('/local/downloadcenter/index.php', ['courseid' => $course->id]);
    $node = $parentnode->add_node(...);
    $node->add_class('downloadcenterlink');
}

// 2. Mapa de iconos FontAwesome
function local_downloadcenter_get_fontawesome_icon_map() {
    return [
        'local_downloadcenter:icon' => 'fa-arrow-circle-o-down',
    ];
}
```

**Nota**: En Moodle 5.x esto debería migrarse al Hook API (`core\hook\navigation\primary_extend` o similar), pero el plugin aún usa el sistema antiguo.

### 3.4 index.php

Página principal del plugin. Flujo:

1. **Inicialización**: sube timeouts, requiere login y capacidad.
2. **Obtiene recursos**: crea un `local_downloadcenter_factory` con el curso y usuario.
3. **Llama a `get_resources_for_user()`**: obtiene las actividades visibles para el usuario.
4. **Carga JS**: `local_downloadcenter/modfilter` para filtros.
5. **Crea formulario** de selección con `local_downloadcenter_download_form`.
6. **Lógica del formulario**:
   - Si enviado → dispara evento `zip_downloaded` y crea ZIP con `create_zip()`
   - Si cancelado → redirige al curso
   - Si no enviado → dispara evento `plugin_viewed` y muestra formulario

### 3.5 locallib.php

**El corazón del plugin** (~1344 líneas). Contiene la clase `local_downloadcenter_factory`.

#### Propiedades clave

```php
private $availableresources = [
    'resource', 'folder', 'publication', 'page', 'book',
    'lightboxgallery', 'assign', 'glossary', 'etherpadlite', 'subsection'
];
```

Define qué tipos de actividad soporta el plugin.

#### Métodos principales

| Método | Propósito |
|--------|-----------|
| `get_resources_for_user()` | Obtiene actividades del curso filtradas por visibilidad y permisos |
| `parse_form_data($data)` | Procesa lo que seleccionó el usuario en el formulario |
| `create_zip()` | Crea el archivo ZIP con los recursos seleccionados |
| `shorten_filename()` | Acorta nombres de archivo a 64 caracteres |
| `convert_content_to_html_doc()` | Convierte contenido a HTML completo |
| `handle_resource()` | Maneja archivos del tipo `resource` |
| `handle_folder()` | Maneja carpetas |
| `handle_assign()` | Maneja tareas (incluye submissions y feedback) |
| `handle_page()` | Maneja páginas |
| `handle_book()` | Maneja libros (con capítulos) |
| `handle_glossary()` | Maneja glosarios |
| `handle_publication()` | Maneja publicaciones (estudiante) |
| `handle_lightboxgallery()` | Maneja galerías lightbox |
| `handle_etherpadlite()` | Maneja pads Etherpad Lite |
| `preprocess_resource_names()` | Procesa nombres de recursos (numeración, duplicados) |
| `section_pathnames()` | Construye estructura de directorios para el ZIP |

#### Flujo de `get_resources_for_user()`:

1. Obtiene `modinfo` del curso
2. Itera secciones del curso
3. Para cada módulo en el curso:
   - Filtra por `$this->availableresources`
   - Filtra por visibilidad (usuarios no ven ocultos)
   - Filtra por permisos (ej: glosario requiere `manageentries` o `allowprintview`)
4. Reemplaza subsecciones con sus recursos reales
5. Retorna array de secciones con sus recursos

### 3.6 download_form.php

Formulario extenso (145 líneas) que construye:

- **Mensaje informativo** diferente para estudiantes y profesores
- **Caja de búsqueda** (template Mustache)
- **Por cada sección**: checkbox para seleccionar/deseleccionar toda la sección
- **Por cada recurso**: checkbox individual con icono y nombre
- **Subsecciones**: agrupadas visualmente
- **Opciones de descarga**:
  - `filesrealnames`: usar nombre original del archivo
  - `addnumbering`: añadir numeración a archivos/carpetas
- **Botón**: "Create ZIP archive"

### 3.7 styles.css

205 líneas de CSS específico para la ruta `.path-local-downloadcenter`. Incluye:

- Ocultación de labels del formulario (clase `fitem femptylabel`)
- Estilos para el layout de secciones y subsecciones (`.card.block`, `.subsection`)
- Fixes para el tema Boost
- Estilos responsive para el título de los items
- Estilos para el botón de limpiar búsqueda

### 3.8 classes/event/

Dos eventos personalizados que extienden `\core\event\base`:

**`plugin_viewed.php`**:
- Se dispara cuando un usuario ve la página del Download Center
- `crud = 'r'` (read)
- `edulevel = LEVEL_PARTICIPATING`
- Almacena el `objectid` = course id

**`zip_downloaded.php`**:
- Se dispara cuando un usuario descarga un ZIP
- `crud = 'c'` (create)
- `edulevel = LEVEL_PARTICIPATING`
- Almacena el `objectid` = course id

Ambos se registran en la tabla `logstore_standard_log` y son visibles en Reportes > Logs.

### 3.9 classes/privacy/provider.php

Implementa `\core_privacy\local\metadata\null_provider`:

```php
class provider implements \core_privacy\local\metadata\null_provider {
    public static function get_reason(): string {
        return 'privacy:null_reason';
    }
}
```

Esto indica que el plugin **no almacena ningún dato personal**. Es obligatorio desde Moodle 3.18+ para cumplir con GDPR. El string `privacy:null_reason` está definido en el archivo de idioma.

### 3.10 lang/en/local_downloadcenter.php

49 cadenas de idioma. Las más importantes:

```php
$string['pluginname'] = 'Download center';
$string['navigationlink'] = 'Download center';
$string['downloadcenter:view'] = 'View Download center';
$string['createzip'] = 'Create ZIP archive';
$string['download'] = 'Download';
$string['downloadoptions'] = 'Options';
$string['infomessage_students'] = '...';
$string['infomessage_teachers'] = '...';
$string['privacy:null_reason'] = 'This plugin does not store or process any personal information.';
```

### 3.11 templates/searchbox.mustache

Template Mustache que renderiza:

- Un input de búsqueda con id `#downloadcenter-search-input`
- Un botón para limpiar la búsqueda
- Un contenedor para resultados
- **Incluye JS inline** al final (carga el módulo AMD `local_downloadcenter/search`)

### 3.12 amd/src/

**`modfilter.js`** (196 líneas):
- Añade enlaces "Select All / None" para cada tipo de módulo
- Añade enlaces "Select All / None" globales
- Maneja el check de checkboxes: si marcas una sección, marca todos sus items
- Usa jQuery, strings de Moodle y URLs

**`search.js`** (108 líneas):
- Búsqueda en vivo mientras el usuario escribe
- Filtra secciones y actividades por nombre
- Oculta/muestra elementos con clase `d-none`
- Desmarca checkboxes de elementos ocultos al enviar el formulario
- Usa JavaScript **ES6 moderno** (const, let, arrow functions, export)
- **Sin dependencias de jQuery** (DOM API puro)

### 3.13 tests

**`files_visible_test.php`** (263 líneas):
- Tests de visibilidad: estudiantes NO ven actividades ocultas, profesores SÍ
- Crea cursos, usuarios, enrolamientos y actividades de prueba
- 3 tests: `test_empty()`, `test_student_visibility()`, `test_teacher_visibility()`

**`locallib_test.php`** (183 líneas):
- Tests unitarios para `preprocess_resource_names()`
- Prueba numeración, duplicados, HTML decoding
- Usa ReflectionMethod para probar métodos privados

**`behat/check_activities.feature`** (78 líneas):
- 5 escenarios Behat que prueban folder, resource, page y book
- Verifica que estudiantes y profesores ven las actividades en el Download Center

---

## 4. Cómo funciona el flujo completo

```
Usuario navega a un curso
        │
        ▼
Ve enlace "Download center" en la navegación del curso
        │  (añadido por lib.php → extend_navigation_course)
        ▼
Hace clic → /local/downloadcenter/index.php?courseid=XX
        │
        ▼
index.php:
  1. Verifica login y capacidad (require_capability)
  2. Crea factory con curso y usuario
  3. get_resources_for_user() escanea el curso:
     - Filtra solo tipos soportados (resource, folder, assign, etc.)
     - Estudiantes: solo ven actividades visibles
     - Profesores: ven todo (incluyendo oculto)
  4. Dispara evento "plugin_viewed"
  5. Muestra formulario con checkboxes
        │
        ▼
Usuario selecciona actividades y opciones, hace clic "Create ZIP"
        │
        ▼
index.php (post):
  1. parse_form_data() filtra solo lo seleccionado
  2. Dispara evento "zip_downloaded"
  3. create_zip():
     - Construye estructura de carpetas (secciones → subcarpetas)
     - Para cada recurso, maneja según su tipo (resource, page, assign, etc.)
     - Agrega archivos al ZIP
     - Descarga el ZIP al navegador
```

---

## 5. Sistema de permisos

| Capacidad | ¿Quién la tiene? | ¿Qué controla? |
|-----------|-----------------|----------------|
| `local/downloadcenter:view` | student, teacher, editingteacher, manager | Ver el enlace en navegación y acceder a la página |

**Además**, el plugin verifica capacidades **existentes de Moodle**:

| Capacidad de Moodle | ¿Dónde se usa? | Efecto |
|---------------------|----------------|--------|
| `moodle/course:viewhiddensections` | `get_resources_for_user()` | Profesores ven secciones ocultas |
| `moodle/course:viewhiddenactivities` | `get_resources_for_user()` | Profesores ven actividades ocultas |
| `moodle/course:update` | `download_form.php` | Muestra mensaje diferente para teachers |
| `mod/glossary:manageentries` | `get_resources_for_user()` | Filtra glosarios sin permiso de impresión |
| `mod/assign:viewgrades` | `handle_assign()` | Estudiantes solo ven sus submissions |
| `mod/publication:approve` | `handle_publication()` | Profesores ven todas las publicaciones |

**Importante**: No usa el sistema de hooks de Moodle 5.x. Los callbacks `extend_navigation_course()` y `get_fontawesome_icon_map()` son del sistema antiguo. En una versión moderna, `extend_navigation_course` se migraría a un hook.

---

## 6. Lo que hace bien (para aprender)

### 6.1 Organización del código

- **Separación clara**: `index.php` (routing), `locallib.php` (lógica), `download_form.php` (UI)
- **Una clase principal** `local_downloadcenter_factory` con métodos bien definidos
- **Eventos personalizados** para logging de actividad

### 6.2 Seguridad

- `require_course_login($course)` asegura que el usuario está matriculado
- `require_capability('local/downloadcenter:view', $context)` controla acceso
- Uso de `required_param()` con `PARAM_INT` en lugar de `$_GET` directo
- Verifica `has_capability()` antes de mostrar datos sensibles

### 6.3 Privacidad (GDPR)

- Implementa `\core_privacy\local\metadata\null_provider` correctamente
- Declara explícitamente que no almacena datos personales

### 6.4 Manejo de errores

- Usa `MUST_EXIST` en consultas BD para forzar error si no existe
- `core_php_time_limit::raise()` y `raise_memory_limit(MEMORY_HUGE)` para operaciones largas

### 6.5 Rendimiento

- Pre-carga instancias de módulos con `get_records_list()` (1 consulta por tipo en lugar de 1 por actividad)
- Usa `get_fast_modinfo()` que cachea la información del curso
- Usa `\core_files\archive_writer::get_stream_writer()` para streamear el ZIP

### 6.6 JavaScript moderno

- `search.js` usa ES6 modules (`export const init`) y sin jQuery
- `modfilter.js` usa jQuery (quizás por compatibilidad con versiones anteriores)
- Ambos compilados a `amd/build/` para producción

### 6.7 Tests

- Tests PHPUnit que prueban visibilidad con datos reales (cursos, usuarios, enrolamientos)
- Tests Behat que simulan el flujo completo del usuario
- Tests unitarios con ReflectionMethod para probar métodos privados

---

## 7. Lo que hace distinto a tu plugin de práctica

| Aspecto | Tu plugin (`local_message`) | `local_downloadcenter` |
|---------|----------------------------|------------------------|
| **Hook API** | Usa hooks (moderno) | Usa callbacks antiguos |
| **DB** | Tiene `install.xml` con tablas | Sin BD |
| **Idioma** | Español (es) | Inglés (en) |
| **JS** | No tiene | AMD modules con build |
| **CSS** | No tiene | `styles.css` con responsive |
| **Templates** | No tiene | Mustache template |
| **Tests** | No tiene | PHPUnit + Behat |
| **Eventos** | No tiene | 2 eventos personalizados |
| **Privacidad** | No tiene | `privacy/provider.php` |
| **Icono** | No tiene | SVG + PNG en `pix/` |
| **Formulario** | No tiene | `moodleform` extenso |
| **Capacidades** | 0 | 1 (`local/downloadcenter:view`) |

### Cosas que puedes aprender de este plugin

1. **Cómo manejar formularios complejos** con secciones, checkboxes agrupados y opciones
2. **Cómo crear eventos personalizados** para logging
3. **Cómo implementar el provider de privacidad** (GDPR)
4. **Cómo estructurar un plugin grande** con `locallib.php` para la lógica pesada
5. **Cómo hacer tests PHPUnit** que crean datos reales (cursos, usuarios, actividades)
6. **Cómo hacer tests Behat** que simulan el flujo completo del usuario
7. **Cómo integrar JavaScript AMD** con templates Mustache
8. **Cómo crear el archivo `styles.css`** con rutas específicas del plugin (`.path-local-downloadcenter`)
9. **Cómo manejar la creación de archivos ZIP** con `\core_files\archive_writer`
10. **Cómo usar `get_fast_modinfo()`** para obtener información del curso de forma eficiente
