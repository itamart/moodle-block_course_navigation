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

require_once("$CFG->dirroot/blocks/navigation/block_navigation.php");

/**
 * The course navigation tree block class
 */
class block_course_navigation extends block_navigation {

    /**
     *
     */
    function specialization() {
        // load userdefined title and make sure it's never empty
        if (empty($this->config->title)) {
            $this->title = get_string('pluginname', $this->blockname);
        } else {
            $this->title = $this->config->title;
        }
        
        // Use hierarchy
        if (empty($this->config->usehierarchy)) {
            $this->usehierarchy = false;
        } else {
            $this->usehierarchy = true;
        }
        
        // Hierarchy
        if (empty($this->config->hierarchy)) {
            $this->hierarchy = array();
        } else {
            $this->hierarchy = explode(',', $this->config->hierarchy);
        }
    }

    /**
     * All multiple instances of this block
     * @return bool true
     */
    function instance_allow_multiple() {
        return true;
    }

    /**
     * Set the applicable formats for this block to all
     * @return array
     */
    function applicable_formats() {
        return array('all' => true);
    }

    /**
     *
     * @return bool true
     */
    function  instance_can_be_hidden() {
        return true;
    }

    /**
     * Gets the content for this block by grabbing it from $this->page
     *
     * @return object $this->content
     */
    function get_content() {
        global $CFG, $OUTPUT;
        // First check if we have already generated, don't waste cycles
        if ($this->contentgenerated === true) {
            return $this->content;
        }
        // JS for navigation moved to the standard theme, the code will probably have to depend on the actual page structure
        // $this->page->requires->js('/lib/javascript-navigation.js');
        // Navcount is used to allow us to have multiple trees although I dont' know why
        // you would want two trees the same

        block_navigation::$navcount++;

        // Check if this block has been docked
        if ($this->docked === null) {
            $this->docked = get_user_preferences('nav_in_tab_panel_globalnav'.block_navigation::$navcount, 0);
        }

        // Check if there is a param to change the docked state
        if ($this->docked && optional_param('undock', null, PARAM_INT)==$this->instance->id) {
            unset_user_preference('nav_in_tab_panel_globalnav'.block_navigation::$navcount);
            $url = $this->page->url;
            $url->remove_params(array('undock'));
            redirect($url);
        } else if (!$this->docked && optional_param('dock', null, PARAM_INT)==$this->instance->id) {
            set_user_preferences(array('nav_in_tab_panel_globalnav'.block_navigation::$navcount=>1));
            $url = $this->page->url;
            $url->remove_params(array('dock'));
            redirect($url);
        }

        $trimmode = self::TRIM_LEFT;
        $trimlength = 50;

        if (!empty($this->config->trimmode)) {
            $trimmode = (int)$this->config->trimmode;
        }

        if (!empty($this->config->trimlength)) {
            $trimlength = (int)$this->config->trimlength;
        }

        // Get the navigation object or don't display the block if none provided.
        if (!$navigation = $this->get_navigation()) {
            return null;
        }
        $expansionlimit = null;
        if (!empty($this->config->expansionlimit)) {
            $expansionlimit = $this->config->expansionlimit;
            $navigation->set_expansion_limit($this->config->expansionlimit);
        }
        $this->trim($navigation, $trimmode, $trimlength, ceil($trimlength/2));

        // Get the expandable items so we can pass them to JS
        $expandable = array();
        $navigation->find_expandable($expandable);
        if ($expansionlimit) {
            foreach ($expandable as $key=>$node) {
                if ($node['type'] > $expansionlimit && !($expansionlimit == navigation_node::TYPE_COURSE && $node['type'] == $expansionlimit && $node['branchid'] == SITEID)) {
                    unset($expandable[$key]);
                }
            }
        }

        $this->page->requires->data_for_js('navtreeexpansions'.$this->instance->id, $expandable);

        $options = array();
        $options['linkcategories'] = (!empty($this->config->linkcategories) && $this->config->linkcategories == 'yes');

        // Grab the items to display
        $renderer = $this->page->get_renderer($this->blockname);
        $this->content = new stdClass();
        $this->content->text = $renderer->navigation_tree($navigation, $expansionlimit, $options);

        // Set content generated to true so that we know it has been done
        $this->contentgenerated = true;

        return $this->content;
    }

    /**
     * Returns the navigation
     *
     * @return navigation_node The navigation object to display
     */
    protected function get_navigation() {
        // Find the course navigation node
        if (!$coursenode = $this->get_course_node()) {
            return null;
        }
 
        $coursenavigation = new global_navigation($this->page);
        $coursenavigation->key = $coursenode->key;
        $coursenavigation->type = $coursenode->type;
        $coursenavigation->text = $coursenode->get_title();
        $coursenavigation->action = $coursenode->action;

        if ($coursesections = $this->get_section_nodes($coursenode, true)) {
            // Skip section 0
            array_slice($coursesections, 1, null, true);
            // Generate the navigation object
            $parent = $coursenavigation;
            if ($this->usehierarchy) {
                $hierarchy = $this->hierarchy;
            } else {
                $hierarchy = array_map(function($id) {return "0 $id";}, array_keys($this->get_section_names()));
            }
            $this->set_navigation_node($coursenavigation, $coursesections, $hierarchy);            
        }
        
        return $coursenavigation;
    }

    /**
     *
     */
    protected function set_navigation_node(&$parent, $sections, $hierarchy) {
        static $key = 0;
        static $sectioncount = 0;
        static $currentlevel = 0;
        while ($key < count($hierarchy)) {
            list($level, $labelorid) = explode(' ', $hierarchy[$key], 2);
            if ($level == $currentlevel) {
                // A label
                if (strpos($labelorid, 'l:') === 0) {
                    if (strpos($labelorid, '|') !== false) {
                        // label with link
                        list($labelorid, $url) = explode('|', substr($labelorid, 2));
                        $currentnode = $parent->add($labelorid, new moodle_url($url));
                    } else {
                        // just label
                        $currentnode = $parent->add(substr($labelorid, 2));
                    }
                // A section
                } else {
                    if (!empty($sections[$labelorid])) {
                        $section = $sections[$labelorid];
                        //$currentnode = $parent->add_node($section);
                        $properties = array(
                            'key' => $section->key,
                            'type' => $section->type,
                            'text' => $section->text,
                            'action' => $section->action,
                        );
                        $currentnode = $parent->add_node(new navigation_node($properties));
                        $sectioncount++;
                    }
                }
                $key++;
            } else if ($level > $currentlevel) {
                $currentlevel = $level;
                $currentnode = empty($currentnode) ? $parent : $currentnode;
                $this->set_navigation_node($currentnode, $sections, $hierarchy);
            } else if ($level < $currentlevel) {
                $currentlevel--;
                break;        
            }
        }
    }

    /**
     *
     */
    public function get_course_node() {
        // Initialise (only actually happens if it hasn't already been done yet
        $this->page->navigation->initialise();
        $navigation = clone($this->page->navigation);
        
        // Find the course navigation node
        if ($coursenode = $navigation->find($this->page->course->id, navigation_node::TYPE_COURSE)) {
            return $coursenode;
        } else {
            return null;
        }
    }
    
    /**
     *
     */
    public function get_section_nodes($coursenode, $sortbyid = false) {
        // Find the course navigation node
        if ($coursenode) {
            if ($sections = $coursenode->find_all_of_type(navigation_node::TYPE_SECTION)) {
                if ($sortbyid) {
                    $sortsections = array();
                    foreach ($sections as $section) {
                        $sortsections[$section->key] = $section;
                    }
                    ksort($sortsections);
                    return $sortsections;
                } else {
                    return $sections;
                }
            }
        }
        return null;
    }
    
    /**
     *
     */
    public function get_section_names() {
        $sectionnames = array();
        // Find the course navigation node
        if ($coursenode = $this->get_course_node()) {
            if ($sections = $this->get_section_nodes($coursenode)) {
                foreach ($sections as $key => $section) {
                    // Skip section 0
                    if (!$key) {
                        continue;
                    }
                    $sectionnames[$section->key] = $section->get_title();
                }
            }
        }
        return $sectionnames;
    }
    
}
