<?php
// This file is part of Moodle - http://moodle.org/
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
 * @package    block
 * @subpackage course_navigation
 * @copyright  2012 Itamar Tzadok
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once("$CFG->dirroot/blocks/navigation/edit_form.php");
require_once("$CFG->dirroot/lib/modinfolib.php");

class block_course_navigation_edit_form extends block_navigation_edit_form {
    /**
     * @param MoodleQuickForm $mform
     */
    protected function specific_definition($mform) {
        global $CFG;

        $blockname = $this->block->blockname;

        // buttons
    	$this->add_action_buttons();

        // block title
        //-------------------------------------------------------------------------------
        $mform->addElement('header', '', get_string('title', $blockname));

        $mform->addElement('text', 'config_title', get_string('title', $blockname));
        $mform->setDefault('config_title', get_string('pluginname', $blockname));
        $mform->setType('config_title', PARAM_MULTILANG);

        // The navigation block settings
        parent::specific_definition($mform);
        
        // Section hierarchy
        //-------------------------------------------------------------------------------
        $mform->addElement('header', '', get_string('hierarchy', $blockname));
        // Use hierarchy flag
        $mform->addElement('checkbox', 'config_usehierarchy', null, get_string('usehierarchy', $blockname));
        // Hierarchy content
        $mform->addElement('textarea', 'config_hierarchy', get_string('hierarchy', $blockname), array('cols' => 60, 'rows' => 10));        
        $mform->disabledIf('config_hierarchy', 'config_usehierarchy', 'notchecked');
        // Update names flag
        $mform->addElement('checkbox', 'config_updatenames', null, get_string('updatenames', $blockname));
        $mform->disabledIf('config_updatenames', 'config_usehierarchy', 'notchecked');
        // Update names flag
        $mform->addElement('checkbox', 'config_updateorder', null, get_string('updateorder', $blockname));
        $mform->disabledIf('config_updateorder', 'config_usehierarchy', 'notchecked');
    }

    /**
     *
     */
    function definition_after_data() {
        $sectionnames = $this->block->get_section_names();

        // Set the hierarchy display in the textarea
        $confighierarchy = &$this->_form->getElement('config_hierarchy');
        if ($confighierarchy->getValue()) {
            $hierarchy = explode(',', $confighierarchy->getValue());
        } else {
            $hierarchy = array_map(function($id) {return "0 $id";}, array_keys($sectionnames));
        }

        $markup = '';
        foreach ($hierarchy as $node) {
            list($level, $id) = explode(' ', $node, 2);
            // label
            if (strpos($id, 'l:') === 0) {
                $sectionname = $id;                
            // section
            } else {
                if (!empty($sectionnames[$id])) {
                    $sectionname = "$sectionnames[$id]|$id";
                    unset($sectionnames[$id]);
                } else {
                    $sectionname = null;
                }
            }
            if ($sectionname) {
                $markup .= str_pad('', $level, '-'). " $sectionname\n";
            }
        }

        // Add all sections not in hierarchy as comments
        if (!empty($sectionnames)) {
            foreach ($sectionnames as $id => $sectionname) {
                if ($sectionname) {
                    $markup .= "# $sectionname|$id\n";
                }
            }
        }

        $confighierarchy->setValue($markup);
    }

    /**
     *
     */
    function get_data() {
        if ($data = parent::get_data()) {

            // process the new hierarchy
            if (!empty($data->config_usehierarchy)) {
                list($hierarcy, $sectionnames, $sectionorder) = $this->post_process_hierarchy($data);
                // Update hierarchy
                $data->config_hierarchy = $hierarcy;
                // Update section names
                if (!empty($data->config_updatenames) and $sectionnames) {
                    $this->update_names($sectionnames);
                    $rebuild = true;
                }
                // Update section order
                if (!empty($data->config_updateorder) and $sectionorder) {
                    $this->update_order($sectionorder);
                    $rebuild = true;
                }
                if (!empty($rebuild)) {
                    rebuild_course_cache($this->block->page->course->id);
                }
                
            } else {                
                $data->config_usehierarchy = 0;
                unset($data->config_hierarchy);
            }
            
            if (empty($data->config_updatenames)) {
                $data->config_updatenames = 0;
            }
            if (empty($data->config_updateorder)) {
                $data->config_updateorder = 0;
            }
        }
        return $data;
    }

    /**
     *
     */
    function post_process_hierarchy($data) {
        $newhierarchy = '';
        $sectionnames = array();
        $sectionorder = array();
        if (!empty($data->config_hierarchy)) {
            $hierarchy = array();            
            $items = explode("\n", trim($data->config_hierarchy));

            foreach ($items as $item) {
                // Skip empty items or commented items
                if (!$item = trim($item) or strpos($item, '#') === 0) {
                    continue;
                }               
                $level = 0;
                // The level is the number of - in the beginning of the string
                if (strpos($item, '-') === 0) {
                    while ($item[$level] == '-') {
                        $level++;
                    }
                    
                    if (!$item = trim(substr($item, $level))) {
                        continue;
                    }
                }
                // For sections set label to id
                if (strpos($item, '|') !== false) {
                    list($label, $idorlink) = explode('|', $item);
                } else {
                    $label = $item;
                    $idorlink = '';
                }
                // For section collate names and order
                if (strpos($label, 'l:') !== 0) {
                    $sectionnames[$idorlink] = $label;                        
                    $sectionorder[] = $idorlink;                        
                    $hierarchy[] = "$level $idorlink";
                } else {
                    if ($idorlink) {
                        $label = "$label|$idorlink";
                    }
                    $hierarchy[] = "$level $label";
                }
            }

            $newhierarchy = implode(',', $hierarchy); 
        }
        return array($newhierarchy, $sectionnames, $sectionorder);
    }
    
    /**
     *
     */
    function update_names(array $sectionnames) {
        global $DB;

        if ($sectionnames) {
            $params = array();
            $sql = "UPDATE {course_sections} SET name = CASE id ";
            foreach ($sectionnames as $id => $name) {
                $sql .= " WHEN ? THEN ? ";
                $params[] = $id;
                $params[] = $name;
            }
            list($inids, $paramids) = $DB->get_in_or_equal(array_keys($sectionnames));
            $sql .= " END WHERE id $inids ";
            $params = array_merge($params, $paramids);
            $DB->execute($sql, $params);
        }
    }

    /**
     *
     */
    function update_order(array $sectionorder) {
        global $DB;
        if ($sectionorder) {
            // Get all the sections for the course
            $courseid = $this->block->page->course->id;
            if (!$sections = $DB->get_records_menu('course_sections', array('course' => $courseid), 'section ASC', 'id,section')) {
                return false;
            }
            
            // Add any sections not in the hierarchy
            foreach ($sections as $id => $order) {
                if (in_array($id, $sectionorder)) {
                    continue;
                } else if ($order) {
                    $sectionorder[] = $id;
                }
            }
            // First normalize with negatives
            $params = array();
            list($inids, $paramids) = $DB->get_in_or_equal($sectionorder);
            $sql = "UPDATE {course_sections} SET section = CASE id ";
            foreach ($sectionorder as $order => $id) {
                $sql .= " WHEN ? THEN ? ";
                $params[] = $id;
                $params[] = (-($order + 1));
            }
            $sql .= " END WHERE id $inids ";
            $params = array_merge($params, $paramids);
            $DB->execute($sql, $params);
            // Now change to positives
            $params = array();
            foreach ($sectionorder as $order => $id) {
                $params[] = $id;
                $params[] = ($order + 1);
            }
            $params = array_merge($params, $paramids);
            $DB->execute($sql, $params);
        }
    }
}
