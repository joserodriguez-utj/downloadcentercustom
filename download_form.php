<?php
// This file is part of local_downloadcentercustom for Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Download center plugin
 *
 * @package       local_downloadcentercustom
 * @author        Simeon Naydenov (moniNaydenov@gmail.com)
 * @copyright     2020 Academic Moodle Cooperation {@link http://www.academic-moodle-cooperation.org}
 * @license       http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @modified      2026 José Luis Rodriguez Escobedo (jose.rodriguez@utj.edu.mx)
 *               Universidad Tecnológica de Jalisco — joserodriguez-utj
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');
require_once(__DIR__ . '/locallib.php');

/**
 * Class local_downloadcentercustom_download_form
 */
class local_downloadcentercustom_download_form extends moodleform {
    /**
     * Form definition
     *
     * @throws coding_exception
     */
    public function definition() {
        global $COURSE, $OUTPUT, $USER;
        $mform = $this->_form;

        $resources = $this->_customdata['res'];

        $mform->addElement('hidden', 'courseid', $COURSE->id);
        $mform->setType('courseid', PARAM_INT);

        $coursecontext = \context_course::instance($COURSE->id);
        $candownloadmaterials = has_capability('local/downloadcentercustom:downloadMaterials', $coursecontext);
        $candownloadassign = has_capability('local/downloadcentercustom:downloadAssignments', $coursecontext);
        $candownloadquiz = has_capability('local/downloadcentercustom:downloadQuiz', $coursecontext);
        $candownloadanything = $candownloadmaterials || $candownloadassign || $candownloadquiz;

        if ($candownloadanything) {
            $infomessagestring = $candownloadmaterials ?
                get_string('infomessage_teachers', 'local_downloadcentercustom') :
                get_string('infomessage_teachers_nomat', 'local_downloadcentercustom');
            $mform->addElement(
                'html',
                html_writer::tag(
                    'div',
                    $infomessagestring,
                    ['class' => 'alert alert-info alert-block']
                )
            );
        } else {
            $mform->addElement(
                'html',
                html_writer::tag(
                    'div',
                    get_string('no_download_permission', 'local_downloadcentercustom'),
                    ['class' => 'alert alert-warning alert-block']
                )
            );
        }
        if ($candownloadanything) {
            $mform->addElement('html', $OUTPUT->render_from_template('local_downloadcentercustom/searchbox', []));
            $mform->addElement('static', 'warning', '', ''); // Hack to work around fieldsets!
        }

        $mform->addElement('html', '<div id="mode-panel-normal">');

        $mform->addElement('html', '<div id="opciones-container">');
        // Modo de descarga: Normal o Portafolio.
        $mform->addElement('html', '<div id="modo-selector">');
        $mform->addElement('html', '<div class="form-group row fitem downloadcenter_selector"><div class="col-md-3"></div><div class="col-md-9"><span class="itemtitle" style="font-weight:bold;">' . get_string('download_mode_title', 'local_downloadcentercustom') . '</span></div></div>');
        $mform->addElement('html', '<div class="form-group row fitem downloadcenter_selector"><div class="col-md-3"></div><div class="col-md-9">');
        $mform->addElement('radio', 'downloadmode', '', get_string('mode_normal', 'local_downloadcentercustom'), 'normal', ['class' => 'mode-radio']);
        $mform->addElement('radio', 'downloadmode', '', get_string('mode_portfolio', 'local_downloadcentercustom'), 'portafolio', ['class' => 'mode-radio']);
        $mform->setDefault('downloadmode', 'normal');
        $mform->addElement('html', '</div></div>');
        $mform->addElement('html', '</div>'); // cierra modo-selector
        if ($candownloadanything) {
            $mform->addElement('html', '<div class="form-group row fitem downloadcenter_selector" id="opciones-title"><div class="col-md-3"></div><div class="col-md-9"><span class="itemtitle" style="font-weight:bold; margin-left:-1rem;">' . get_string('content_to_download', 'local_downloadcentercustom') . '</span></div></div>');
        }
        // Detectar que modnames existen en el curso.
        $modnamesincourse = [];
        foreach ($resources as $sec) {
            foreach ($sec->res as $r) {
                $modnamesincourse[$r->modname] = true;
            }
        }
        $showfiles = isset($modnamesincourse['resource']);
        $showfolders = isset($modnamesincourse['folder']);
        $showurls = isset($modnamesincourse['url']);
        $showpages = isset($modnamesincourse['page']);
        $havequiz = isset($modnamesincourse['quiz']);
        $havesomematerials = $showfiles || $showfolders || $showurls || $showpages;

        if ($candownloadmaterials && $havesomematerials) {
            $mform->addElement('html', '<div class="form-group row fitem downloadcenter_selector"><div class="col-md-3"></div><div class="col-md-9"><span class="itemtitle"><strong>' . get_string('materials', 'local_downloadcentercustom') . '</strong></span></div></div>');
            $mform->addElement('html', '<div style="display:flex;flex-wrap:wrap;gap:0;padding-left:1rem;">');
            $mform->addElement('html', '<div class="separator"></div>');
            if ($showfiles) { $mform->addElement('checkbox', 'includefiles', get_string('files', 'local_downloadcentercustom')); $mform->setDefault('includefiles', 1); }
            if ($showfolders) { $mform->addElement('checkbox', 'includefolders', get_string('folders', 'local_downloadcentercustom')); $mform->setDefault('includefolders', 1); }
            if ($showurls) { $mform->addElement('checkbox', 'includeurls', get_string('urls', 'local_downloadcentercustom')); $mform->setDefault('includeurls', 1); }
            if ($showpages) { $mform->addElement('checkbox', 'includepages', get_string('pages', 'local_downloadcentercustom')); $mform->setDefault('includepages', 1); }
            $mform->addElement('html', '</div>');
        }
        if ($candownloadassign) {
            $mform->addElement('html', '<div class="form-group row fitem downloadcenter_selector"><div class="col-md-3"></div><div class="col-md-9"><span class="itemtitle"><strong>' . get_string('tasks', 'local_downloadcentercustom') . '</strong></span></div></div>');
            $mform->addElement('html', '<div style="display:flex;flex-wrap:wrap;gap:0;padding-left:1rem;">');
            $mform->addElement('html', '<div class="separator"></div>');
            $mform->addElement('checkbox', 'onlytasks', get_string('assignments', 'local_downloadcentercustom'));
            $mform->setDefault('onlytasks', 1);
            $mform->addElement('checkbox', 'includefeedback', get_string('feedback', 'local_downloadcentercustom'));
            $mform->setDefault('includefeedback', 1);
            $mform->addElement('checkbox', 'includeinstructions', get_string('instructions', 'local_downloadcentercustom'));
            $mform->setDefault('includeinstructions', 0);
            $mform->addElement('checkbox', 'includeresources', get_string('resources_item', 'local_downloadcentercustom'));
            $mform->setDefault('includeresources', 0);
            $mform->addElement('html', '</div>');
        }
        if ($candownloadquiz && $havequiz) {
            $mform->addElement('html', '<div class="form-group row fitem downloadcenter_selector"><div class="col-md-3"></div><div class="col-md-9"><span class="itemtitle"><strong>' . get_string('quiz', 'local_downloadcentercustom') . '</strong></span></div></div>');
            $mform->addElement('html', '<div style="display:flex;flex-wrap:wrap;gap:0;padding-left:1rem;">');
            $mform->addElement('html', '<div class="separator"></div>');
            $mform->addElement('checkbox', 'quiztries', get_string('quiz_tries', 'local_downloadcentercustom'));
            $mform->setDefault('quiztries', 1);
            $mform->addElement('html', '</div>');
        }
        $mform->addElement('html', '</div>');
        $mform->addElement('html', <<<JS
<script>
document.addEventListener("DOMContentLoaded", function() {
    var ot = document.getElementById("id_onlytasks");
    var qtries = document.getElementById("id_quiztries");
    var ifiles = document.getElementById("id_includefiles");
    var ifolders = document.getElementById("id_includefolders");
    var iurls = document.getElementById("id_includeurls");
    var ipages = document.getElementById("id_includepages");
    var ii = document.getElementById("id_includeinstructions");
    var ir = document.getElementById("id_includeresources");
    var fi = document.getElementById("id_includefeedback");
    var form = document.querySelector("form.mform");

    function moverOpciones() {
        var card = document.querySelector(".grouped_settings.section_level.block.card");
        var container = document.getElementById("opciones-container");
        var title = document.getElementById("opciones-title");
        var modo = document.getElementById("modo-selector");
        if (card && container && title) {
            card.insertBefore(title, card.firstChild);
            if (modo) {
                card.insertBefore(modo, title);
            }
            card.appendChild(container);
        } else {
            setTimeout(moverOpciones, 100);
        }
    }
    moverOpciones();

    // Retro, Instrucciones y Recursos dependen de Entregas (o de al menos una tarea seleccionada).
    function updateDependents() {
        var ena = ot && (ot.checked || document.querySelectorAll('input[name^="item_assign_"]:checked').length > 0);
        var fb = document.getElementById("id_includefeedback");
        var ins = document.getElementById("id_includeinstructions");
        var rec = document.getElementById("id_includeresources");
        if (fb) { fb.disabled = !ena; if (!ena) fb.checked = false; }
        if (ins) { ins.disabled = !ena; if (!ena) ins.checked = false; }
        if (rec) { rec.disabled = !ena; if (!ena) rec.checked = false; }
    }
    if (ot) {
        ot.addEventListener("change", updateDependents);
        form.addEventListener("change", function(e) {
            if (e.target && e.target.name && e.target.name.indexOf('item_assign_') === 0) {
                updateDependents();
            }
        });
    }
    updateDependents();

    if (!ot && !qtries) return;

    function toggleByModname(modname, checked) {
        document.querySelectorAll('input[name^="item_' + modname + '_"]').forEach(function(el) {
            el.checked = checked;
            el.dispatchEvent(new Event("change", {bubbles: true}));
        });
        // Actualizar checkboxes de sección
        document.querySelectorAll('.card.block').forEach(function(card) {
            var sec = card.querySelector('input[name^="item_topic_"]');
            if (!sec) return;
            var items = card.querySelectorAll('input.form-check-input[name^="item_"]:not([name="' + sec.name + '"])');
            if (items.length === 0) return;
            sec.checked = Array.from(items).some(function(it) { return it.checked; });
        });
        if (form && M.form && M.form.updateFormState) {
            M.form.updateFormState(form.id);
        }
    }

    if (ifiles) {
        ifiles.addEventListener("click", function() {
            var checked = this.checked;
            toggleByModname("resource", checked);
            toggleByModname("label", checked);
            toggleByModname("book", checked);
        });
    }
    if (ipages) {
        ipages.addEventListener("click", function() {
            toggleByModname("page", this.checked);
        });
    }
    if (ifolders) {
        ifolders.addEventListener("click", function() {
            toggleByModname("folder", this.checked);
        });
    }
    if (iurls) {
        iurls.addEventListener("click", function() {
            toggleByModname("url", this.checked);
        });
    }
    if (ot) {
        ot.addEventListener("click", function() {
            var checked = this.checked;
            toggleByModname("assign", checked);
            toggleByModname("h5pactivity", checked);
            toggleByModname("forum", checked);
            toggleByModname("lesson", checked);
            toggleByModname("workshop", checked);
            toggleByModname("data", checked);
        });
    }
    if (qtries) {
        qtries.addEventListener("click", function() {
            toggleByModname("quiz", this.checked);
        });
    }

    // Select All/None tambien controla checkboxes de contenido.
    function triggerChange(id) { var e = document.getElementById(id); if (e) e.dispatchEvent(new Event("change", {bubbles:true})); }
    document.addEventListener("click", function(e) {
        var target = e.target;
        if (target.id === "downloadcenter-all-included") {
            if (ifiles) ifiles.checked = true;
            if (ifolders) ifolders.checked = true;
            if (iurls) iurls.checked = true;
            if (ipages) ipages.checked = true;
            if (ot) { ot.checked = true; } if (ii) ii.checked = true; if (ir) ir.checked = true; if (fi) fi.checked = true;
            if (qtries) qtries.checked = true;
            ["includefiles","includefolders","includeurls","includepages","onlytasks","includefeedback","includeinstructions","includeresources","quiztries"].forEach(triggerChange);
        }
        if (target.id === "downloadcenter-none-included") {
            if (ifiles) ifiles.checked = false;
            if (ifolders) ifolders.checked = false;
            if (iurls) iurls.checked = false;
            if (ipages) ipages.checked = false;
            if (ot) { ot.checked = false; } if (ii) ii.checked = false; if (ir) ir.checked = false; if (fi) fi.checked = false;
            if (qtries) qtries.checked = false;
            ["includefiles","includefolders","includeurls","includepages","onlytasks","includefeedback","includeinstructions","includeresources","quiztries"].forEach(triggerChange);
        }
    });
});
</script>
JS
);

        $firstbox = true;
        foreach ($resources as $sectionid => $sectioninfo) {
            $mode = $data['downloadmode'] ?? 'normal';
            // Filtrar los recursos según las capacidades del usuario.
            $sectioninfo->res = array_filter($sectioninfo->res, function($r) use ($candownloadmaterials, $candownloadassign, $candownloadquiz) {
                if ($r->modname === 'quiz') {
                    return $candownloadquiz;
                }
                if (in_array($r->modname, ['assign', 'publication', 'h5pactivity', 'forum', 'lesson', 'workshop', 'data'])) {
                    return $candownloadassign;
                }
                return $candownloadmaterials;
            });
            if (empty($sectioninfo->res)) {
                continue;
            }
            $sectionname = 'item_topic_' . $sectionid;
            $class = 'card block mb-3';
            // Small margin for the first box for better separation.
            $class .= $firstbox ? ' mt-3' : '';
            $firstbox = false;
            $mform->addElement('html', html_writer::start_tag('div', ['class' => $class]));
            $sectiontitle = html_writer::span($sectioninfo->title, 'sectiontitle');

            if (!$sectioninfo->visible) {
                $sectiontitle .= html_writer::tag(
                    'span',
                    get_string('hiddenfromstudents'),
                    ['class' => 'badge bg-info text-white ml-1 sectiontitlebadge']
                );
            }
            $currentsubsectionitemid = -1;
            if (empty($sectioninfo->res)) {
                $mform->addElement('html', '<div class="form-group row fitem"><div class="col-md-12"></div><div class="col-md-9"><span class="itemtitle"><strong>' . $sectiontitle . '</strong></span><br><em>' . get_string('no_content', 'local_downloadcentercustom') . '</em></div></div>');
            } else {
                $mform->addElement('checkbox', $sectionname, $sectiontitle, '', ['class' => 'mt-2']);
                $mform->setDefault($sectionname, 1);
            }
            foreach ($sectioninfo->res as $res) {
                if (!empty($res->issubresource)) {
                    if ($currentsubsectionitemid != -1 && $currentsubsectionitemid != $res->subsectioncmid) {
                        $mform->addElement('html', html_writer::end_tag('div'));
                    }
                    if ($currentsubsectionitemid != $res->subsectioncmid) {
                        $mform->addElement('html', html_writer::start_tag('div', ['class' => 'card block subsection mb-3 mr-3']));

                        $sectiontitle = html_writer::span($res->subsectionname, 'sectiontitle');
                        $sectionname = 'item_topic_' . $res->subsectioncmid;
                        $mform->addElement('checkbox', $sectionname, $sectiontitle, '', ['class' => 'mt-2']);
                        $mform->setDefault($sectionname, 1);
                    }
                    $currentsubsectionitemid = $res->subsectioncmid;
                } else {
                    if ($currentsubsectionitemid != -1) {
                        $mform->addElement('html', html_writer::end_tag('div'));
                    }
                    $currentsubsectionitemid = -1;
                }

                // Saltar recursos que el usuario no puede descargar según su capacidad.
                if ($res->modname === 'quiz') {
                    if (!$candownloadquiz) {
                        continue;
                    }
                } else if (in_array($res->modname, ['assign', 'publication', 'h5pactivity', 'forum', 'lesson', 'workshop', 'data'])) {
                    if (!$candownloadassign) {
                        continue;
                    }
                } else if (!$candownloadmaterials) {
                    continue;
                }

                $name = 'item_' . $res->modname . '_' . $res->instanceid;
                $title = html_writer::span($res->name) . ' ' . $res->icon;
                $badge = '';
                $title = html_writer::tag('span', $title . $badge, ['class' => 'itemtitle']);
                $showcheckbox = true;
                if (!$candownloadmaterials && in_array($res->modname, ['page', 'resource'])) {
                    $showcheckbox = false;
                }
                if ($showcheckbox) {
                    $mform->addElement('checkbox', $name, $title);
                    $mform->setDefault($name, 1);
                } else {
                    $mform->addElement('html', '<div class="form-group row fitem"><div class="col-md-12"><span class="itemtitle">' . $title . '</span></div></div>');
                }
            }
            if ($currentsubsectionitemid != -1) {
                $mform->addElement('html', html_writer::end_tag('div'));
            }
            $mform->addElement('html', html_writer::end_tag('div'));
        }

        // Create a new section for the download options!
        // Opciones deshabilitadas - ya no se usan.
        // $mform->addElement('header', 'downloadoptions', get_string('downloadoptions', 'local_downloadcentercustom'));
        // $mform->addElement('checkbox', 'filesrealnames', get_string('downloadoptions:filesrealnames', 'local_downloadcentercustom'));
        // $mform->setDefault('filesrealnames', 0);
        // $mform->addHelpButton('filesrealnames', 'downloadoptions:filesrealnames', 'local_downloadcentercustom');
        // $mform->addElement('checkbox', 'addnumbering', get_string('downloadoptions:addnumbering', 'local_downloadcentercustom'));
        // $mform->setDefault('addnumbering', 0);
        // $mform->addHelpButton('addnumbering', 'downloadoptions:addnumbering', 'local_downloadcentercustom');

        // Group filtering (solo si tiene permiso de descargar tareas o exámenes).
        $groups = [];
        $studentoptions = [];
        $studentgroups = [];
        if ($candownloadassign || $candownloadquiz) {
            $canaccessallgroups = has_capability('local/downloadcentercustom:downloadMaterials', $coursecontext);
            if ($canaccessallgroups) {
                $groups = groups_get_all_groups($COURSE->id);
            } else {
                $usergroups = groups_get_user_groups($COURSE->id, $USER->id);
                $groups = [];
                if (!empty($usergroups[0])) {
                    foreach ($usergroups[0] as $gid) {
                        $group = groups_get_group($gid);
                        if ($group) {
                            $groups[$gid] = $group;
                        }
                    }
                }
            }
            // Estudiantes elegibles para el portafolio (con sus grupos para poder filtrarlos).
            $context = \context_course::instance($COURSE->id);
            $students = get_enrolled_users($context, 'mod/assign:submit');
            // Sin grupos asignados (onlyungrouped) o con un único grupo no se muestra el
            // select de grupos; limitamos los estudiantes a los que le corresponden.
            $ungroupedonly = (count($groups) === 0);
            $singlegroup = (count($groups) === 1) ? (int) array_key_first($groups) : null;
            foreach ($students as $e) {
                $ug = groups_get_user_groups($COURSE->id, $e->id);
                $studentgroups[$e->id] = $ug[0] ?? [];
                if ($ungroupedonly && !empty($studentgroups[$e->id])) {
                    continue;
                }
                if ($singlegroup !== null && !in_array($singlegroup, $studentgroups[$e->id], true)) {
                    continue;
                }
                $studentoptions[$e->id] = fullname($e);
            }

            // Mostrar el filtro solo cuando el usuario tiene 2+ grupos asignados.
            if (count($groups) >= 2) {
                $groupoptions = [];
                foreach ($groups as $group) {
                    $groupoptions[$group->id] = $group->name;
                }
                $mform->addElement('html', '<div class="separator"></div>');
                $mform->addElement('header', 'groupfilter', get_string('groupfilter', 'local_downloadcentercustom'));
                $mform->setExpanded('groupfilter');
                $mform->addElement('checkbox', 'selectallgroups', get_string('all_groups', 'local_downloadcentercustom'));
                $mform->setDefault('selectallgroups', 0);
                $select = $mform->addElement('autocomplete', 'selectedgroups',
                    get_string('select_groups_one_by_one', 'local_downloadcentercustom'), $groupoptions);
                $select->setMultiple(true);
                $mform->addHelpButton('selectedgroups', 'groupfilter_help', 'local_downloadcentercustom');
                $mform->setDefault('selectedgroups', []);
                $mform->addElement('html', '
                <script>
                    document.getElementById("id_selectallgroups").onclick = function() {
                        var checked = this.checked;
                        var sel = document.getElementById("id_selectedgroups");
                        var container = sel.parentElement.querySelector(".form-autocomplete-selection");
                        if (!container) return;
                        container.innerHTML = "";
                        if (checked) {
                            for (var i = 0; i < sel.options.length; i++) {
                                var opt = sel.options[i];
                                if (!opt.value) continue;
                                opt.selected = true;
                                var tag = document.createElement("span");
                                tag.className = "badge bg-secondary text-dark m-1";
                                tag.style.fontSize = "100%";
                                tag.setAttribute("role", "option");
                                tag.setAttribute("data-value", opt.value);
                                tag.setAttribute("aria-selected", "true");
                                var removeBtn = document.createElement("span");
                                removeBtn.setAttribute("aria-hidden", "true");
                                removeBtn.textContent = "\u00d7 ";
                                tag.appendChild(removeBtn);
                                tag.appendChild(document.createTextNode(" "));
                                tag.appendChild(document.createTextNode(opt.text));
                                container.appendChild(tag);
                            }
                        } else {
                            for (var i = 0; i < sel.options.length; i++) {
                                sel.options[i].selected = false;
                            }
                        }
                    };
                    // Sincronizar checkbox de "Todos" cuando se deseleccionan grupos manualmente
                    var sel = document.getElementById("id_selectedgroups");
                    if (sel) {
                        sel.addEventListener("change", function() {
                            var allGroups = document.getElementById("id_selectallgroups");
                            if (!allGroups) return;
                            allGroups.checked = Array.from(sel.options).filter(function(o) {
                                return o.value;
                            }).every(function(o) {
                                return o.selected;
                            });
                        });
                    }
                </script>');
            }

        // ===== Sección de estudiantes: dentro del desplegable "Filtrar por grupos" (solo portafolio). =====
        $mform->addElement('html', '<div id="portfolio-students-section" style="display:none;">');
        $mform->addElement('html', '<div class="form-group row fitem downloadcenter_selector"><div class="col-md-9" style="margin-left:2rem;"><span class="itemtitle"><strong>' . get_string('select_students', 'local_downloadcentercustom') . '</strong></span></div></div>');

        // Etiqueta equivalente a "Seleccionar grupo uno por uno".
        $studentselect = $mform->addElement('autocomplete', 'selectedstudents', get_string('select_students_one_by_one', 'local_downloadcentercustom'), $studentoptions);
        $studentselect->setMultiple(true);
        $mform->setDefault('selectedstudents', []);
        $mform->addElement('checkbox', 'selectallstudents', get_string('all_students', 'local_downloadcentercustom'));
        $mform->setDefault('selectallstudents', 0);
        $mform->addHelpButton('selectedstudents', 'selectedstudents_help', 'local_downloadcentercustom');
        $mform->addElement('html', '<div class="alert alert-info" id="portfolio-selected-info" style="margin:10px 0;padding:8px 12px;font-size:0.9em;display:none;"></div>');
        $mform->addElement('html', '</div>');
        } 

        if (($candownloadassign || $candownloadquiz) && count($groups) >= 2) {
            $mform->addElement('html', '<div class="alert alert-info" id="nota-grupos-alert" style="margin:10px 0;padding:8px 12px;font-size:0.9em;">');
            $mform->addElement('html', '<strong>' . get_string('note', 'local_downloadcentercustom') . '</strong>');
            $mform->addElement('html', '<ul style="margin:4px 0 0 20px;padding:0;">');
            if ($candownloadmaterials) {
                $mform->addElement('html', '<li>' . get_string('infomessage_download', 'local_downloadcentercustom') . '</li>');
            }
            $mform->addElement('html', '<li>' . get_string('infomessage_download_assignment', 'local_downloadcentercustom') . '</li>');
            $mform->addElement('html', '</ul>');
            $mform->addElement('html', '</div>');
            $mform->addElement('html', '</div>'); // Cierra mode-panel-normal
        }

        $this->add_action_buttons(true, get_string('createzip', 'local_downloadcentercustom'));
        $mform->addElement('html', <<<JS
<script>
document.addEventListener("DOMContentLoaded", function() {
    var mats = ["id_includefiles","id_includefolders","id_includeurls","id_includepages"];
    var sel = document.getElementById("id_selectedgroups");
    var allGroups = document.getElementById("id_selectallgroups");
    var btn = document.querySelector("input[name='buttonar[submitbutton]']");
    var form = document.querySelector("form.mform");

    function hasmat() {
        return mats.some(function(id) {
            var el = document.getElementById(id);
            return el && el.checked;
        });
    }
    function hastask() {
        var ids = ["id_onlytasks","id_includefeedback","id_includeinstructions","id_includeresources","id_quiztries"];
        return ids.some(function(id) {
            var el = document.getElementById(id);
            return el && el.checked;
        });
    }
    function hasgroups() {
        return !sel || (allGroups && allGroups.checked) || (sel && Array.from(sel.options).some(function(o) { return o.selected; }));
    }
    function hasSelectedItems() {
        return Array.from(document.querySelectorAll('input[name^="item_"]:checked')).some(function(el) {
            return el.name.indexOf('item_topic_') !== 0;
        });
    }
    function syncMaterialFiltersFromItems() {
        var checkedMods = {};
        document.querySelectorAll('input[name^="item_"]:checked').forEach(function(el) {
            var match = el.name.match(/^item_([a-z]+)_\d+$/);
            if (match) {
                checkedMods[match[1]] = true;
            }
        });
        var files = document.getElementById('id_includefiles');
        if (files) { files.checked = !!(checkedMods.resource || checkedMods.label || checkedMods.book); }
        var folders = document.getElementById('id_includefolders');
        if (folders) { folders.checked = !!checkedMods.folder; }
        var urls = document.getElementById('id_includeurls');
        if (urls) { urls.checked = !!checkedMods.url; }
        var pages = document.getElementById('id_includepages');
        if (pages) { pages.checked = !!checkedMods.page; }
        var tasks = document.getElementById('id_onlytasks');
        if (tasks) { tasks.checked = !!(checkedMods.assign || checkedMods.workshop || checkedMods.data); }
        var quiztries = document.getElementById('id_quiztries');
        if (quiztries) { quiztries.checked = !!checkedMods.quiz; }
        // Sincronizar checkboxes de seccion.
        document.querySelectorAll('input[name^="item_topic_"]').forEach(function(el) {
            var section = el.closest('.card.block');
            if (!section) return;
            var items = section.querySelectorAll('input[name^="item_"]:not([name^="item_topic_"]):checked');
            el.checked = items.length > 0;
        });
    }
    function currentDownloadMode() {
        var r = document.querySelector('input[name="downloadmode"]:checked');
        return r ? r.value : 'normal';
    }
    function canSubmit() {
        if (currentDownloadMode() === 'portafolio') {
            var allChk = document.getElementById("id_selectallstudents");
            var sel = document.getElementById("id_selectedstudents");
            var allChecked = allChk && allChk.checked;
            var someSel = sel && Array.from(sel.options).some(function(o) { return o.value && o.selected; });
            return !!(allChecked || someSel);
        }
        if (hastask() && !hasgroups()) {
            return false;
        }
        return hasmat() || hasSelectedItems() || (hastask() && hasgroups());
    }
    function check() {
        if (!btn) {
            return;
        }
        btn.disabled = !canSubmit();
    }
    function onSelectionChanged() {
        syncMaterialFiltersFromItems();
        check();
    }

    mats.forEach(function(id) {
        var el = document.getElementById(id);
        if (el) {
            el.addEventListener("change", check);
        }
    });
    var onlytasks = document.getElementById("id_onlytasks");
    if (onlytasks) {
        onlytasks.addEventListener("change", check);
    }
    var quiztries = document.getElementById("id_quiztries");
    if (quiztries) {
        quiztries.addEventListener("change", check);
    }
    if (allGroups) {
        allGroups.addEventListener("change", check);
    }
    if (sel) {
        sel.addEventListener("change", check);
    }
    if (form) {
        form.addEventListener("change", function(e) {
            if (e.target && e.target.name && e.target.name.indexOf('item_') === 0) {
                onSelectionChanged();
            }
        });
        form.addEventListener("submit", function(e) {
            if (!canSubmit()) {
                e.preventDefault();
                return false;
            }
        });
    }
    document.addEventListener('downloadcenter:itemselectionchanged', onSelectionChanged);
    document.addEventListener('click', function(e) {
        if (e.target.id === 'downloadcenter-none-included' || e.target.id === 'downloadcenter-all-included') {
            setTimeout(onSelectionChanged, 0);
        }
    });
    window.__dcCheck = check;
    window.__dcCanSubmit = canSubmit;
    check();
});
</script>
JS
);
        // JS del selector de modo de descarga (Normal / Portafolio).
        $studentgroupsjson = json_encode($studentgroups);
        $mform->addElement('html', <<<JS
<script>
document.addEventListener("DOMContentLoaded", function() {
    var radios = document.querySelectorAll('input[name="downloadmode"]');
    var portfolioPanel = document.getElementById("portfolio-students-section");
    var selectAll = document.getElementById("id_selectallstudents");
    var selectedStudents = document.getElementById("id_selectedstudents");
    var submitBtn = document.querySelector("input[name='buttonar[submitbutton]']");
    var dcStudentGroups = $studentgroupsjson;

    function currentMode() {
        var m = "normal";
        radios.forEach(function(r) { if (r.checked) m = r.value; });
        return m;
    }

    function portfolioSelectedCount() {
        if (!selectedStudents) return 0;
        return Array.from(selectedStudents.options).filter(function(o) {
            return o.value && o.selected;
        }).length;
    }

    function studentBelongsToSelectedGroups(uid) {
        var selectedGroups = document.getElementById("id_selectedgroups");
        var allGroups = document.getElementById("id_selectallgroups");
        if (!selectedGroups) return true;
        if (allGroups && allGroups.checked) return true;
        var selected = [];
        Array.from(selectedGroups.options).forEach(function(o) {
            if (o.value && o.selected) selected.push(o.value);
        });
        if (selected.length === 0) return true;
        var groups = (dcStudentGroups && dcStudentGroups[uid]) || [];
        return groups.some(function(g) {
            return selected.indexOf(String(g)) !== -1;
        });
    }

    function populateAllStudents() {
        if (!selectedStudents) return;
        var container = selectedStudents.parentElement.querySelector('.form-autocomplete-selection');
        if (container) container.innerHTML = '';
        Array.from(selectedStudents.options).forEach(function(o) {
            if (!o.value) return;
            var enabled = o.getAttribute('data-enabled') !== 'disabled';
            o.selected = enabled;
            if (enabled && container) {
                var tag = document.createElement("span");
                tag.className = "badge bg-secondary text-dark m-1";
                tag.style.fontSize = "100%";
                tag.setAttribute("role", "option");
                tag.setAttribute("data-value", o.value);
                tag.setAttribute("aria-selected", "true");
                var removeBtn = document.createElement("span");
                removeBtn.setAttribute("aria-hidden", "true");
                removeBtn.textContent = "\u00d7 ";
                tag.appendChild(removeBtn);
                tag.appendChild(document.createTextNode(" "));
                tag.appendChild(document.createTextNode(o.text));
                container.appendChild(tag);
            }
        });
    }

    function applyStudentGroupFilter(reopen) {
        if (!selectedStudents) return;
        var isAll = selectAll && selectAll.checked;
        var cleared = [];
        Array.from(selectedStudents.options).forEach(function(o) {
            if (!o.value) return;
            if (studentBelongsToSelectedGroups(o.value)) {
                o.removeAttribute("data-enabled");
            } else {
                o.setAttribute("data-enabled", "disabled");
                // Deseleccionar estudiantes que ya no pertenecen a los grupos elegidos.
                if (o.selected && !isAll) {
                    o.selected = false;
                    cleared.push(o.value);
                }
            }
        });
        // Si "Todos los estudiantes" está marcado, repoblar con solo los habilitados
        // (coherente con el grupo seleccionado en el filtro).
        if (isAll) {
            populateAllStudents();
            return;
        }
        if (cleared.length && selectedStudents.parentElement) {
            var region = selectedStudents.parentElement.querySelector('.form-autocomplete-selection');
            if (region) {
                cleared.forEach(function(v) {
                    var tag = region.querySelector('[data-value="' + v + '"]');
                    if (tag) tag.remove();
                });
            }
        }
        // Re-render las sugerencias del autocomplete de estudiantes al cambiar el filtro.
        if (reopen && selectedStudents.parentElement) {
            var arrow = selectedStudents.parentElement.querySelector('.form-autocomplete-downarrow');
            if (arrow) arrow.click();
        }
        if (typeof window.__dcCheck === 'function') {
            window.__dcCheck();
        }
    }

    function hidingNormalBits() {
        var bits = [];
        var t = document.getElementById("opciones-title");
        if (t) { bits.push(t); }
        var c = document.getElementById("opciones-container");
        if (c) { bits.push(c); }
        var m = document.getElementById("mod_select_links");
        if (m) { bits.push(m); }
        var link = document.getElementById("downloadcenter-all-included");
        if (link && link.closest) {
            var row = link.closest(".downloadcenter_selector");
            if (row) { bits.push(row); }
        }
        Array.prototype.forEach.call(document.querySelectorAll("#mode-panel-normal > div.card.block.mb-3"), function(el) {
            bits.push(el);
        });
        // En modo portafolio se ocultan el "Todos los grupos" y la nota de selección.
        var allGroups = document.getElementById("id_selectallgroups");
        if (allGroups) {
            var allGroupsItem = allGroups.closest(".fitem");
            if (allGroupsItem) { bits.push(allGroupsItem); }
        }
        var groupNote = document.getElementById("nota-grupos-alert");
        if (groupNote) { bits.push(groupNote); }
        return bits;
    }

    function setModeUI() {
        var portafolio = (currentMode() === 'portafolio');
        if (portfolioPanel) {
            portfolioPanel.style.display = portafolio ? '' : 'none';
        }
        hidingNormalBits().forEach(function(el) {
            el.classList.toggle('dc-hidden', portafolio);
        });
        if (portafolio) {
            if (selectedStudents) {
                selectedStudents.disabled = selectAll ? selectAll.checked : false;
            }
            applyStudentGroupFilter(false);
        } else {
            if (selectedStudents) {
                selectedStudents.disabled = false;
            }
        }
        if (typeof window.__dcCheck === 'function') {
            window.__dcCheck();
        }
    }

    radios.forEach(function(r) {
        r.addEventListener("change", setModeUI);
    });
    if (selectAll) {
        selectAll.addEventListener("change", function() {
            var checked = selectAll.checked;
            if (selectedStudents) {
                selectedStudents.disabled = checked;
                if (checked) {
                    // Solo los habilitados por el filtro de grupos (data-enabled).
                    populateAllStudents();
                } else {
                    var container = selectedStudents.parentElement.querySelector(".form-autocomplete-selection");
                    if (container) { container.innerHTML = ""; }
                    Array.from(selectedStudents.options).forEach(function(o) {
                        if (o.value) { o.selected = false; }
                    });
                }
            }
            if (typeof window.__dcCheck === 'function') {
                window.__dcCheck();
            }
        });
    }
    if (selectedStudents) {
        selectedStudents.addEventListener("change", function() {
            if (typeof window.__dcCheck === 'function') {
                window.__dcCheck();
            }
        });
    }
    // El filtro de grupos compartido también filtra los estudiantes del portafolio.
    var groupSel = document.getElementById("id_selectedgroups");
    var groupAll = document.getElementById("id_selectallgroups");
    if (groupSel) {
        // En modo portafolio solo se permite elegir un grupo: al hacer clic en una
        // sugerencia (fase de captura, antes del handler de Moodle) se limpia la
        // selección previa para que Moodle deje únicamente la nueva opción.
        // El ul de sugerencias se resuelve en cada clic porque Moodle lo renderiza
        // de forma asíncrona (puede no existir aún al dispararse DOMContentLoaded).
        document.addEventListener("click", function(e) {
            if (currentMode() !== 'portafolio') return;
            if (!groupSel || !groupSel.parentElement) return;
            var groupSuggestions = groupSel.parentElement.querySelector('ul.form-autocomplete-suggestions');
            if (!groupSuggestions) return;
            // Solo imponemos un solo grupo si el clic ocurre DENTRO de las sugerencias
            // de grupos. Evita deseleccionar grupos al hacer clic en otros widgets
            // (p. ej. las sugerencias de estudiantes, que también tienen role="option").
            if (!groupSuggestions.contains(e.target)) return;
            var optionEl = e.target.closest ? e.target.closest('[role="option"][data-value]') : null;
            if (!optionEl) return;
            Array.from(groupSel.options).forEach(function(o) {
                if (o.value && o.selected) { o.selected = false; }
            });
        }, true);
        groupSel.addEventListener("change", function() {
            if (currentMode() === 'portafolio') {
                // Guard por si la selección llega por teclado: dejar solo un grupo
                // (el sugerido activo o, en su defecto, el último).
                var groupSuggestions = groupSel.parentElement ? groupSel.parentElement.querySelector('ul.form-autocomplete-suggestions') : null;
                var options = Array.from(groupSel.options).filter(function(o) { return o.value && o.selected; });
                if (options.length > 1) {
                    var keepVal = null;
                    var sug = groupSuggestions ? groupSuggestions.querySelector('[aria-selected="true"]') : null;
                    if (sug && sug.getAttribute("data-value")) { keepVal = sug.getAttribute("data-value"); }
                    if (!keepVal || !options.some(function(o) { return o.value === keepVal; })) {
                        keepVal = options[options.length - 1].value;
                    }
                    options.forEach(function(o) { if (o.value !== keepVal) { o.selected = false; } });
                    // Re-renderizar las etiquetas de la selección para que coincidan.
                    var region = groupSel.parentElement.querySelector('.form-autocomplete-selection');
                    var keepOpt = Array.from(groupSel.options).find(function(o) { return o.value === keepVal; });
                    if (region) {
                        region.innerHTML = "";
                        if (keepOpt) {
                            var tag = document.createElement("span");
                            tag.setAttribute("role", "option");
                            tag.setAttribute("data-value", keepVal);
                            tag.setAttribute("aria-selected", "true");
                            tag.className = "badge bg-secondary text-dark m-1";
                            tag.style.fontSize = "100%";
                            tag.innerHTML = '<span aria-hidden="true">\u00d7 </span>';
                            tag.appendChild(document.createTextNode(keepOpt.text));
                            region.appendChild(tag);
                        }
                    }
                }
                applyStudentGroupFilter(false);
            }
        });
    }
    if (groupAll) {
        groupAll.addEventListener("change", function() {
            if (currentMode() === 'portafolio') {
                applyStudentGroupFilter(false);
            }
        });
    }
    setModeUI();
});
</script>
JS
);

        // Acciones comunes para el toggle del panel normal/portafolio.
        $mform->addElement('html', <<<JS
<script>
document.addEventListener("DOMContentLoaded", function() {
    // Sincronizar "Todos los estudiantes" cuando se seleccionan estudiantes individuales.
    var selectAll = document.getElementById("id_selectallstudents");
    var selectedStudents = document.getElementById("id_selectedstudents");
    if (selectAll && selectedStudents) {
        selectedStudents.addEventListener("change", function() {
            var anySel = Array.from(selectedStudents.options).some(function(o) { return o.value && o.selected; });
            if (anySel) {
                selectAll.checked = false;
            }
        });
    }

    // Al enviar en modo portafolio, si "Todos" está marcado limpiamos selectedstudents.
    var form = document.querySelector("form.mform");
    if (form) {
        form.addEventListener("submit", function() {
            var mode = "normal";
            document.querySelectorAll('input[name="downloadmode"]').forEach(function(r) { if (r.checked) mode = r.value; });
            if (mode === "portafolio" && selectAll && selectAll.checked && selectedStudents) {
                selectedStudents.removeAttribute("disabled");
                Array.from(selectedStudents.options).forEach(function(o) { o.selected = false; });
            }
        });
    }
});
</script>
JS
);
    }

    function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $mode = $data['downloadmode'] ?? 'normal';
        if ($mode === 'portafolio') {
            $allstudents = !empty($data['selectallstudents']);
            $hassel = !empty($data['selectedstudents']);
            if (!$allstudents && !$hassel) {
                $errors['selectedstudents'] = get_string('selectstudents_required', 'local_downloadcentercustom');
            }
            return $errors;
        }
        $hasmat = !empty($data['includefiles']) || !empty($data['includefolders']) || !empty($data['includeurls']) || !empty($data['includepages']);
        $hastask = !empty($data['onlytasks']) || !empty($data['includefeedback']) || !empty($data['includeinstructions']) || !empty($data['includeresources']) || !empty($data['quiztries']);
        $hasgroups = !empty($data['selectallgroups']) || !empty($data['selectedgroups']);
        global $COURSE, $USER;
        // Solo exigir selección de grupos si el usuario tiene 2+ grupos asignados
        // (0 grupos → alumnos sin grupo; 1 grupo → auto-asignado).
        $usergroups = groups_get_user_groups($COURSE->id, $USER->id);
        $usergroupcount = count($usergroups[0] ?? []);
        if ($hastask && !$hasgroups && $usergroupcount >= 2) {
            $errors['selectedgroups'] = get_string('selectgroup_required', 'local_downloadcentercustom');
        }
        return $errors;
    }
}
