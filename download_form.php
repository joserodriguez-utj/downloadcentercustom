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
        global $COURSE, $OUTPUT;
        $mform = $this->_form;

        $resources = $this->_customdata['res'];

        $mform->addElement('hidden', 'courseid', $COURSE->id);
        $mform->setType('courseid', PARAM_INT);

        $coursecontext = \context_course::instance($COURSE->id);
        $infomessagestring = has_capability('moodle/course:update', $coursecontext) ?
            get_string('infomessage_teachers', 'local_downloadcentercustom') :
            get_string('infomessage_students', 'local_downloadcentercustom');
        $mform->addElement(
            'html',
            html_writer::tag(
                'div',
                $infomessagestring,
                ['class' => 'alert alert-info alert-block']
            )
        );
        $mform->addElement('html', $OUTPUT->render_from_template('local_downloadcentercustom/searchbox', []));
        $mform->addElement('static', 'warning', '', ''); // Hack to work around fieldsets!

        $mform->addElement('html', '<div id="opciones-container">');
        $iseditingteacher = has_capability('moodle/course:update', $coursecontext);
        $mform->addElement('html', '<div class="form-group row fitem downloadcenter_selector" id="opciones-title"><div class="col-md-3"></div><div class="col-md-9"><span class="itemtitle" style="font-weight:bold;">' . get_string('content_to_download', 'local_downloadcentercustom') . '</span></div></div>');
        if ($iseditingteacher) {
            $mform->addElement('html', '<div class="form-group row fitem downloadcenter_selector"><div class="col-md-3"></div><div class="col-md-9"><span class="itemtitle"><strong>' . get_string('materiales', 'local_downloadcentercustom') . '</strong></span></div></div>');
            $mform->addElement('html', '<div style="display:flex;flex-wrap:wrap;gap:10px;padding-left:1rem;">');
            $mform->addElement('html', '<div class="separator"></div>');
            $mform->addElement('checkbox', 'includefiles', get_string('archivos', 'local_downloadcentercustom'));
            $mform->setDefault('includefiles', 1);
            $mform->addElement('checkbox', 'includefolders', get_string('carpetas', 'local_downloadcentercustom'));
            $mform->setDefault('includefolders', 1);
            $mform->addElement('checkbox', 'includeurls', get_string('urls', 'local_downloadcentercustom'));
            $mform->setDefault('includeurls', 1);
            $mform->addElement('html', '</div>');
        }
        $mform->addElement('html', '<div class="form-group row fitem downloadcenter_selector"><div class="col-md-3"></div><div class="col-md-9"><span class="itemtitle"><strong>' . get_string('tareas', 'local_downloadcentercustom') . '</strong></span></div></div>');
        $mform->addElement('html', '<div style="display:flex;flex-wrap:wrap;gap:10px;padding-left:1rem;">');
        $mform->addElement('html', '<div class="separator"></div>');
        $mform->addElement('checkbox', 'onlytasks', get_string('entregas', 'local_downloadcentercustom'));
        $mform->setDefault('onlytasks', 1);
        $mform->addElement('checkbox', 'includefeedback', get_string('retroalimentacion', 'local_downloadcentercustom'));
        $mform->setDefault('includefeedback', 1);
        $mform->addElement('checkbox', 'includeinstructions', get_string('instrucciones', 'local_downloadcentercustom'));
        $mform->setDefault('includeinstructions', 1);
        $mform->addElement('checkbox', 'includeresources', get_string('recursos_item', 'local_downloadcentercustom'));
        $mform->setDefault('includeresources', 1);
        $mform->addElement('html', '</div>');
        $mform->addElement('html', '</div>');
        $mform->addElement('html', <<<JS
<script>
document.addEventListener("DOMContentLoaded", function() {
    var ot = document.getElementById("id_onlytasks");
    var ifiles = document.getElementById("id_includefiles");
    var ifolders = document.getElementById("id_includefolders");
    var iurls = document.getElementById("id_includeurls");
    var ii = document.getElementById("id_includeinstructions");
    var ir = document.getElementById("id_includeresources");
    var fi = document.getElementById("id_includefeedback");
    if (!ot) return;

    function toggleByModname(modname, checked) {
        document.querySelectorAll('input[name^="item_' + modname + '_"]').forEach(function(el) {
            el.checked = checked;
        });
    }

    if (ifiles) {
        ifiles.addEventListener("click", function() {
            toggleByModname("resource", this.checked);
            toggleByModname("page", this.checked);
            toggleByModname("label", this.checked);
            toggleByModname("book", this.checked);
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
    ot.addEventListener("click", function() {
        toggleByModname("assign", this.checked);
    });

    // Select All/None tambien controla checkboxes de contenido.
    document.addEventListener("click", function(e) {
        var target = e.target;
        if (target.id === "downloadcenter-all-included") {
            if (ifiles) ifiles.checked = true;
            if (ifolders) ifolders.checked = true;
            if (iurls) iurls.checked = true;
            ot.checked = true; ii.checked = true; ir.checked = true; fi.checked = true;
        }
        if (target.id === "downloadcenter-none-included") {
            if (ifiles) ifiles.checked = false;
            if (ifolders) ifolders.checked = false;
            if (iurls) iurls.checked = false;
            ot.checked = false; ii.checked = false; ir.checked = false; fi.checked = false;
        }
    });
    function moverOpciones() {
        var card = document.querySelector(".grouped_settings.section_level.block.card");
        var container = document.getElementById("opciones-container");
        var title = document.getElementById("opciones-title");
        if (card && container && title) {
            card.insertBefore(title, card.firstChild);
            card.appendChild(container);
        } else {
            setTimeout(moverOpciones, 100);
        }
    }
    moverOpciones();
});
</script>
JS
);

        $firstbox = true;
        foreach ($resources as $sectionid => $sectioninfo) {
            $sectionname = 'item_topic_' . $sectionid;
            $class = 'card block mb-3';
            // Small margin for the first box for better separation.
            $class .= $firstbox ? ' mt-3' : '';
            $firstbox = false;
            $mform->addElement('html', html_writer::start_tag('div', ['class' => $class]));
            $sectiontitle = html_writer::span($sectioninfo->title, 'sectiontitle mt-1');

            if (!$sectioninfo->visible) {
                $sectiontitle .= html_writer::tag(
                    'span',
                    get_string('hiddenfromstudents'),
                    ['class' => 'badge bg-info text-white ml-1 sectiontitlebadge']
                );
            }
            $currentsubsectionitemid = -1;
            if (empty($sectioninfo->res)) {
                $mform->addElement('html', '<div class="form-group row fitem"><div class="col-md-12"></div><div class="col-md-9"><span class="itemtitle"><strong>' . $sectiontitle . '</strong></span><br><em>' . get_string('sin_contenido', 'local_downloadcentercustom') . '</em></div></div>');
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

                        $sectiontitle = html_writer::span($res->subsectionname, 'sectiontitle mt-1');
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

                $name = 'item_' . $res->modname . '_' . $res->instanceid;
                $title = html_writer::span($res->name) . ' ' . $res->icon;
                $badge = '';
                if (!$res->visible) {
                    $badge = html_writer::tag(
                        'span',
                        get_string('hiddenfromstudents'),
                        ['class' => 'badge bg-info text-white mb-1']
                    );
                }
                if ($res->isstealth) {
                    $badge = html_writer::tag(
                        'span',
                        get_string('hiddenoncoursepage'),
                        ['class' => 'badge bg-info text-white mb-1']
                    );
                }
                $title = html_writer::tag('span', $title . $badge, ['class' => 'itemtitle']);
                $showcheckbox = true;
                if (!$iseditingteacher && in_array($res->modname, ['page', 'resource'])) {
                    $showcheckbox = false;
                }
                if ($showcheckbox) {
                    $mform->addElement('checkbox', $name, $title);
                    $mform->setDefault($name, 1);
                } else {
                    $mform->addElement('html', '<div class="form-group row fitem"><div class="col-md-3"></div><div class="col-md-9"><span class="itemtitle">' . $title . '</span></div></div>');
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

        // Group filtering for teachers.
        $coursecontext = \context_course::instance($COURSE->id);
        if (has_capability('local/downloadcentercustom:view', $coursecontext)) {
            $groups = groups_get_all_groups($COURSE->id);
            if (!empty($groups)) {
                $groupoptions = [];
                foreach ($groups as $group) {
                    $groupoptions[$group->id] = $group->name;
                }
                $mform->addElement('header', 'groupfilter', get_string('groupfilter', 'local_downloadcentercustom'));
                $mform->setExpanded('groupfilter');
                $mform->addElement('checkbox', 'selectallgroups', get_string('todos_los_grupos', 'local_downloadcentercustom'));
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
</script>');
            }
        }

        $this->add_action_buttons(true, get_string('createzip', 'local_downloadcentercustom'));
    }
}
