Centro de descarga de evidencias
================================

Plugin para Moodle que permite a docentes y estudiantes descargar, en un archivo ZIP, los materiales, actividades, entregas y evidencias de un curso.

*Plugin original:* `local_downloadcenter` de Simeon Naydenov / Clemens Marx — [Academic Moodle Cooperation](http://www.academic-moodle-cooperation.org).

*Licencia:* [GNU GPL v3 or later](http://www.gnu.org/copyleft/gpl.html)

---

## Créditos de edición

Este repositorio es una adaptación personalizada del plugin original, desarrollada y mantenida por:

* **José Luis Rodríguez Escobedo** — `jose.rodriguez@utj.edu.mx`
* **Universidad Tecnológica de Jalisco** — `joserodriguez-utj`

Se adaptó a las necesidades de la UTJ: cambio de nombre a **"Centro de descarga de evidencias"**, filtrado por grupos, permisos por rol, descarga de rúbricas y retroalimentaciones, y descarga de resultados de exámenes (quiz).

---

## Versiones

El proyecto cuenta actualmente con **tres versiones** principales:

### V1.0 — Filtro por grupos (rama `main` / `Centro de descarga de evidencias`)

Primera gran adaptación sobre el plugin original. Funcionalidades:

* Filtro de descarga **por grupos** del curso.
  * Docente con 0 grupos → descarga solo alumnos sin grupo.
  * Docente con 1 grupo → se auto-asigna a ese grupo.
  * Docente con 2+ grupos → selecciona uno o varios grupos (o todos).
* **Capacidades por rol** (`downloadMaterials`, `downloadAssignments`).
* Reestructuración del ZIP: materiales a nivel de curso, instrucciones como HTML, labels con archivos.
* Checkbox "Todos / Ninguno" sincronizados con los checkboxes de contenido.
* Botón de descarga bloqueado durante la generación del ZIP para evitar doble descarga.
* Nota informativa sobre la selección de grupos (solo aparece si aplica).
* Descarga de páginas con enlaces a YouTube, Canva y Genially como HTML.
* Cadenas de texto traducidas a español (es_mx) e inglés (en).

### V1.1 — Descarga de rúbricas y retroalimentación (rama `Centro-de-descarga-de-evidencia-V1.1`)

Agrega la descarga de la evaluación de las tareas:

* Descarga de **rúbricas** con criterios, niveles y puntaje.
* Descarga de **retroalimentación** (feedback) con su calificación.
* Generación de HTML con la rúbrica y la calificación de cada estudiante.

### V1.2 — Descarga de exámenes (rama `Centro-de-descarga-de-evidencia-V1.2`)

Agrega la descarga de los resultados de los cuestionarios (quiz):

* Nueva capacidad **`downloadQuizz`** para descargar exámenes.
* Categoría **"Exámenes"** con subcategoría **"Intentos"** en el menú de descarga.
* Generación de un HTML por estudiante con estructura de **reporte calificador**:
  * Una columna por pregunta (`Pregunta N`, `Respuesta N`, `Respuesta correcta N`).
  * Una fila por intento, con los datos: Apellido(s), Nombre, Email, Estado, Iniciado, Finalizado, Duración.
  * Columnas finales: `Calificación intento`, `Método de calificación`, `Calificación final`.
  * `Método de calificación` y `Calificación final` combinadas (una sola fila, sin repetirse).
* Soporte para los cuatro métodos de calificación de Moodle: más alta, promedio, primer intento y último intento.
* Descarga de **todos los intentos** de cada estudiante.

---

## Instalación

1. Copiar el código a `local/downloadcentercustom`.
2. Iniciar sesión en Moodle como administrador.
3. Abrir el área de administración (`http://tu-moodle/admin`) para que se instale automáticamente.

---

## Uso

1. Entrar al curso.
2. Ir al enlace **"Centro de descargas de evidencias"** en la navegación del curso.
3. Seleccionar los materiales, tareas y/o exámenes a descargar.
4. Seleccionar el o los grupos (si aplica).
5. Clic en **"Crear archivo ZIP"**.

---

## Licencia

Este plugin es software libre: puedes redistribuirlo y/o modificarlo bajo los términos de la GNU General Public License publicada por la Free Software Foundation, ya sea la versión 3 de la Licencia, o (a tu elección) cualquier versión posterior.

Se distribuye con la esperanza de que sea útil, pero SIN NINGUNA GARANTÍA; sin siquiera la garantía implícita de COMERCIABILIDAD o IDONEIDAD PARA UN PROPÓSITO PARTICULAR. Ver la GNU General Public License para más detalles.
