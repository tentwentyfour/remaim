<?php
/**
 * ReMaIm – Redmine to Phabricator Importer
 *
 * @package Ttf\Remaim
 * @version  0.2.0 The adolescent years
 * @since    0.2.0 The adolescent years
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

namespace Ttf\Remaim;

use Pimple\Container;

class Journal
{
    use Traits\MarkupConverter;

    private $container;

    public function __construct(Container $c)
    {
        $this->container = $c;
    }

    /**
     * Transforms a Redmine journal entry into a
     * Maniphest comment
     *
     * @param  array  $entry Journal entry
     *
     * @return String         Maniphest comment
     */
    public function transform(array $entry, $project_id)
    {
        if ((!isset($entry['notes']) || empty($entry['notes']))
            && (!isset($entry['details']) || empty($entry['details']))
        ) {
            continue;
        }

        $timestamp = strtotime($entry['created_on']);
        $comment = sprintf(
                'On %s, %s',
                date('l, F jS Y H:i:s', $timestamp),
                $entry['user']['name']
            );

        if (!empty($entry['notes'])) {
            $comment .= sprintf(
                ' wrote:' . PHP_EOL
                . '%s',
                $this->convertToQuote(
                    $this->textileToMarkdown($entry['notes'])
                )
            );
        }

        if (!empty($entry['notes']) && !empty($entry['details'])) {
            $comment .= PHP_EOL . 'and:';
        }

        if (!empty($entry['details'])) {
            $comment .= PHP_EOL . implode(
                PHP_EOL,
                $this->recountStory($entry['details'], $project_id)
            );
        }
        return $comment;
    }

    /**
     * Recreate issue history based on detailed journal actions
     *
     * @param  array $details   Detailed actions taken
     *
     * @return array            Parsed, verbose action story
     */
    public function recountStory($details, $project_id)
    {
        return array_map(function ($action) use ($project_id) {
            if ($action['property'] === 'attr') {
                return $this->parseAttributeAction($action, $project_id);
            } elseif ($action['property'] === 'cf') {
                if (isset($action['old_value']) && !empty($action['old_value'])) {
                    return sprintf(
                        ' - changed "%s" from "%s" to "%s"',
                        $this->getCustomField($action['name']),
                        $action['old_value'],
                        $action['new_value']
                    );
                } else {
                    return sprintf(
                        ' - set "%s" to "%s"',
                        $this->getCustomField($action['name']),
                        $action['new_value']
                    );
                }

            }
        }, $details);
    }

    /**
     * Retrieve the name of a custom field from the Redmine API
     *
     * @param  Integer $id  Custom field ID
     *
     * @return String       Custom field description
     */
    public function getCustomField($id)
    {
        return $this->container['redmine']->getCustomFieldById($id);
    }

    /**
     * Parse actions of type "attr".
     *
     * @param  array $action Array describing the action
     *
     * @return String        Textual representation of the action
     */
    public function parseAttributeAction($action, $project_id)
    {
        switch ($action['name']) {
            case 'due_date':
                $formats = [
                    ' - set due date to %s',
                    ' - changed due date from %s to %s',
                ];
                break;
            case 'estimated_hours':
                $formats = [
                    ' - set estimated hours to %d',
                    ' - changed estimated hours from %d to %d',
                    ' - removed the time estimation of %d hours',
                ];
                break;
            case 'tracker_id':
                $formats = [
                    null,
                    ' - changed the task type from "%s" to "%s"',
                ];
                $action = $this->convert(
                    $action,
                    'getTrackerById'
                );
                break;
            case 'status_id':
                $formats = [
                    ' - set task status to %s',
                    ' - changed task status from "%s" to "%s"',
                ];
                $action = $this->convert(
                    $action,
                    'getStatusById'
                );
                break;
            case 'done_ratio':
                $formats = [
                    ' - set done to %d%%',
                    ' - changed done from %d%% to %d%%',
                    ' - changed done back from %d%% to 0%%',
                ];
                break;
            case 'priority_id':
                $formats = [
                    ' - set the task\'s priority to %s',
                    sprintf(
                        ' - %s the task\'s priority to %%s',
                        $action['new_value'] > $action['old_value'] ? 'Raised' : 'Lowered'
                    ),
                ];
                $action = $this->convert(
                    $action,
                    'getPriorityById'
                );
                break;
            case 'category_id':
                $formats = [
                    ' - set category to "%s"',
                    ' - changed category from "%s" to "%s"',
                ];
                $action = $this->convert(
                    $action,
                    'getCategoryById',
                    null,
                    [$project_id]
                );
                break;
            case 'fixed_version_id':
                $formats = [
                    ' - set target version to "%s"',
                    ' - changed target version from "%s" to "%s"',
                    ' - removed target version "%s"',
                    ' - unset the target version',
                ];
                $action = $this->convert(
                    $action,
                    'getVersionById',
                    null,
                    [$project_id]
                );
                break;
            case 'assigned_to_id':
                $formats = [
                    ' - assigned the task to %s',
                    ' - changed the assignee from %s to %s',
                    ' - unassigned %s',
                ];
                $action = $this->convert(
                    $action,
                    'getUserById',
                    function ($value) {
                        return sprintf(
                            '%s %s',
                            $value['user']['firstname'],
                            $value['user']['lastname']
                        );
                    }
                );
                break;
            case 'description':
                return ' - edited the description';
                break;
            case 'subject':
                return sprintf(
                    ' - changed the subject from "%s" to "%s"',
                    $action['old_value'],
                    $action['new_value']
                );
                break;
            default:
                printf(
                    'Encountered an unknown journal entry: %s' . PHP_EOL,
                    serialize($action)
                );
                return sprintf(
                    ' - changed another property I don\'t know about: %s',
                    serialize($action)
                );
                break;
        }

        if ($this->isModification($action)) {
            return $this->representChange(
                $action,
                $formats[1]
            );
        } elseif ($this->isInitial($action)) {
            return $this->representInitialOrUnsetting(
                $action,
                $formats[0]
            );
        } elseif ($this->isRemoval($action)) {
            if (!isset($formats[2])) {
                printf(
                    'Encountered attribute action with unknown third case: %s',
                    serialize($action)
                );
            }
            return $this->representRemoval(
                $action,
                $formats[2]
            );
        } elseif ($this->isUnset($action)) {
            if (!isset($formats[3])) {
                printf(
                    'Encountered attribute action with unknown fourth case: %s',
                    serialize($action)
                );
            }
            return $this->representInitialOrUnsetting(
                $action,
                $formats[3]
            );
        } else {
            printf(
                'Encountered attribute action with unknown case: %s',
                serialize($action)
            );
        }
    }

    /**
     * Determine whether this journal action is a value newly set
     * or a change from a previous values.
     *
     * @param  array  $action Action to analyse
     *
     * @return boolean        Whether the action is modifying an existing value.
     */
    private function isModification($action)
    {
        return isset($action['old_value'])
        && !empty($action['old_value'])
        && isset($action['new_value'])
        && !empty($action['new_value']);
    }

    private function isInitial($action)
    {
        return isset($action['new_value']) && !empty($action['new_value']);
    }

    private function isRemoval($action)
    {
        return isset($action['old_value'])
        && !empty($action['old_value'])
        && (!isset($action['new_value']) || empty($action['new_value']));
    }

    private function isUnset($action)
    {
        return is_null($action['new_value']);
    }

    /**
     * Represents an attribute first being set.
     *
     * @param  [type] $action [description]
     * @param  [type] $format [description]
     *
     * @return [type]         [description]
     */
    private function representInitialOrUnsetting($action, $format)
    {
        return sprintf($format, $action['new_value']);
    }

    /**
     * Represents the change of an attribute from an old
     * to a new value.
     *
     * @param  [type] $action [description]
     * @param  [type] $format [description]
     *
     * @return [type]         [description]
     */
    private function representChange($action, $format)
    {
        return sprintf($format, $action['old_value'], $action['new_value']);
    }

    /**
     * Represents the removal or reset of an attribute
     *
     * @param  [type] $action [description]
     * @param  [type] $format [description]
     *
     * @return [type]         [description]
     */
    private function representRemoval($action, $format)
    {
        return sprintf($format, $action['old_value']);
    }

    /**
     * Converts IDs into entities by using the specified methods.
     *
     * @todo  use array_filter and array_map or array_reduce
     *
     * @param  array $action    Array containing data to be converted
     * @param  String $converter Method to use for conversion
     *
     * @return
     */
    public function convert($action, $converter, $modifier = null, $params = [])
    {
        $ident = function ($var) { return $var; };
        $modifier = (null === $modifier) ? $ident : $modifier;
        foreach (['old_value', 'new_value'] as $key) {
            if (isset($action[$key])) {
                array_unshift($params, $action[$key]);
                $action[$key] = $modifier(
                    call_user_func_array(
                        [$this->container['redmine'], $converter],
                        $params
                    )
                );
            }
        }
        return $action;
    }
}
