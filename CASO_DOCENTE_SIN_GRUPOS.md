# Caso: docente sin grupos de Moodle y grupo "REMEDIAL"

## Contexto

En ciertas materias (plantilla) el **curso es el grupo académico** del docente:

- Los alumnos **regulares** no tienen ningún grupo de Moodle asignado.
- Los alumnos que no aprobaron regularmente se marcan dentro de un grupo de Moodle
  llamado **REMEDIAL**.
- Ambos conjuntos pertenecen a la **misma** asignación (curso–materia–grupo) y al
  **mismo docente** (p. ej. `adriana.bedoy`, rol `editingteacher`).

Ejemplo real: curso "Inglés VII - IQF 8°B TV" — 21 alumnos; 2 de ellos metidos en
el grupo `REMEDIAL`.

## Cómo funcionaba antes

Regla: **"docente sin grupos de Moodle → solo alumnos sin grupo"**.

- La lista y la descarga incluían únicamente a los alumnos **sin grupo**.
- Consecuencia: los alumnos del grupo `REMEDIAL` quedaban **excluidos**
  (no aparecían ni se podían descargar).
- Reproducido: 21 alumnos → al meter 2 en un grupo, la lista bajaba a **19**.

## Cómo funciona ahora

Regla: **"docente sin grupos de Moodle → todos los alumnos del curso"**.

- Aparecen y se descargan los alumnos **sin grupo** y los de **cualquier grupo**
  (p. ej. `REMEDIAL`).
- Un docente **con** grupos de Moodle sigue viendo **solo** los alumnos de **sus**
  grupos (restricción intacta).
- Reproducido: 21 alumnos → con 2 en un grupo, la lista se mantiene en **21**.

## En qué caso aplica

- **Sí aplica** en materias tipo "curso = grupo académico", con alumnos sin grupo
  + un grupo `REMEDIAL`.
- **No aplica** en materias donde los docentes están asignados a grupos de Moodle
  (p. ej. cursos tipo "TEST", que usan grupos por docente). Ahí cada docente ve
  únicamente sus grupos.

## Nota / advertencia

Un docente **sin** grupos de Moodle en un curso **con** grupos de Moodle verá a
**todos** los alumnos (incluidos los de grupos que no son suyos). Para
restringirlo, hay que **asignarlo a su grupo** de Moodle.

## Archivos afectados

- `download_form.php` — armado de la lista de estudiantes (docente sin grupos →
  todos los alumnos).
- `locallib.php` — `parse_form_data()`: para un docente sin grupos ya no se marca
  `onlyungrouped`, de modo que la descarga también incluye a todos.

## Versión

- Documentado en **v1.9.0**.
