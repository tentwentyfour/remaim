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

    public function __contruct(Container $c)
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
    public function transform(array $entry)
    {
        if ((!isset($entry['notes']) || empty($entry['notes']))
            && (!isset($entry['details']) || empty($entry['details']))
        ) {
            continue;
        }

        $timestamp = strtotime($entry['created_on']);
        $comment = sprintf(
            "On %s, %s wrote:\n %s",
            date('l, F jS Y H:i:s', $timestamp),
            $entry['user']['name'],
            $this->convertToQuote(
                $this->convertFromRedmine($entry['notes'])
            )
        );

        if (!empty($entry['details'])) {
            $comment .= PHP_EOL . 'and:' . PHP_EOL . implode(
                PHP_EOL,
                $this->recountStory($entry['details'])
            );
        }
        return $comment;
    }

    /**
     * Tries to detect whether the content in Redmine is using textile or markdown
     * and then converts some markup (if textile) or just passes it on.
     *
     * This is a really naive and inefficient approach which could be improved.
     *
     * @todo Support external links
     *
     * @param  String $text Input text
     *
     * @return String Converted text
     */
    public function convertFromRedmine($text)
    {
        return str_replace(
            ["\r", 'h1.', 'h2.', 'h3.', 'h4.', '<pre>', '</pre>', '@', '*', '_'],
            ['', '#', '##', '###', '####', '```', '```', '`', '**', '//'],
            trim($text)
        );
    }

    /**
     * Convert some text into a "quote" by prefixing it with "> "
     * and replacing subsequent newlines with "> ".
     *
     * @param  string $text Original text to be transformed into a quote
     *
     * @return string       Quoted text
     */
    public function convertToQuote($text)
    {
        return sprintf(
            '> %s',
            preg_replace("/[\n\r]/", "\n> ", $text)
        );
    }

    /**
     * Recreate issue history based on detailed journal actions
     *
     * @param  array $details   Detailed actions taken
     *
     * @return array            Parsed, verbose action story
     */
    public function recountStory($details)
    {
        return array_map(function ($action) {
            if ($action['property'] === 'attr') {
                return $this->parseAttributeAction($action);
            } elseif ($action['property'] === 'cf') {
                if (isset($action['old_value']) && !empty($action['old_value'])) {
                    return sprintf(
                        ' - changed "%s" from "%s" to "%s"',
                        $this->custom_fields[$action['name']],
                        $action['old_value'],
                        $action['new_value']
                    );
                } else {
                    return sprintf(
                        ' - set "%s" to "%s"',
                        $this->custom_fields[$action['name']],
                        $action['new_value']
                    );
                }

            }
        }, $details);
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
        return isset($action['old_value']) && !empty($action['old_value']);
    }

    private function representChange($action, $format)
    {
        return sprintf($format, $action['old_value'], $action['new_value']);
    }

    private function representInitial($action, $format)
    {
        return sprintf($format, $action['new_value']);
    }

    /**
     * Parse actions of type "attr".
     *
     * @param  array $action Array describing the action
     *
     * @return String        Textual representation of the action
     */
    public function parseAttributeAction($action)
    {
        switch ($action['name']) {
            case 'estimated_hours':
                $formats = [
                    ' - set estimated hours to %d',
                    ' - changed estimated hours from %d to %d',
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
            case 'fixed_version_id':
                $formats = [
                    ' - set target version to "%s"',
                    ' - changed target version from "%s" to "%s"',
                ];
                $action = $this->convert(
                    $action,
                    'getVersionById',
                    [$this->project_id]
                );
                break;
            case 'assigned_to_id':
                $formats = [
                    ' - assigned the task to %s',
                    ' - changed the assignee from %s to %s',
                ];
                $action = $this->convert(
                    $action,
                    ''
                );
                break;
            case 'description':
                return ' - changed the description';
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
                $format[1]
            );
        } else {
            return $this->representInitial(
                $action,
                $format[0]
            );
        }
    }

    /**
     * [convert description]
     * @param  [type] $action    [description]
     * @param  [type] $converter [description]
     * @return [type]            [description]
     */
    public function convert($action, $converter, $params = [])
    {
        array_unshift($params, $action);
        return call_user_func_array(
            [$this->container['redmine'], $converter],
            $params
        );
    }
}