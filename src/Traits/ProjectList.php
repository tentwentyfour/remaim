<?php
/**
 * ReMaIm – Redmine to Phabricator Importer
 *
 * @package Ttf\Remaim
 * @version  0.2.0
 * @since    0.2.0
 *
 * @author  Jonathan Jin <jonathan@tentwentyfour.lu>
 * @author  David Raison <david@tentwentyfour.lu>
 *
 * (c) TenTwentyFour
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 */

namespace Ttf\Remaim\Traits;

trait ProjectList
{
    /**
     * Recursive function to build a tree structure from
     * the projects' parent > child relationships.
     *
     * @param  array  &$projects  List of projects to be put into a tree structure
     * @param  integer $parent    Subject of the current iteration of the function run
     *
     * @return array              Tree structure of projects
     */
    public function buildProjectTree(&$projects, $parent = 0)
    {
        $tmp_array = [];
        foreach ($projects as $project) {
            if ($project['parent']['id'] == $parent) {
                $project['children'] = $this->buildProjectTree($projects, $project['id']);
                $tmp_array[] = $project;
            }
        }
        usort($tmp_array, function ($a, $b) {
            return $a['id'] > $b['id'];
        });
        return $tmp_array;
    }

    /**
     * Visually represents project structure
     *
     * @param  Array  $project  Single project array
     * @param  integer $level   Level of hierarchy-depth
     *
     * @return String           Returns a visual representation of the structure of $project
     */
    public function representProject($project, $level = 0)
    {
        $string = sprintf(
            "[%d – %s]\n",
            $project['id'],
            $project['name']
        );
        if (!empty($project['children'])) {
            $indent = implode('', array_pad([], ++$level, "\t"));
            foreach ($project['children'] as $project) {
                $string .= sprintf("%s└–––––––– %s", $indent, $this->representProject($project, $level));
            }
        }
        return $string;
    }
}