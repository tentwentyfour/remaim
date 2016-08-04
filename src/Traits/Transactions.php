<?php
/**
 * ReMaIm – Redmine to Phabricator Importer
 *
 * @package Ttf\Remaim
 * @version  0.1.1 Short Circuit
 * @since    0.0.1 First public release
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

trait Transactions
{
    /**
     * Create transactions to set policies
     *
     * @param  array $policies Policies to be applied
     *
     * @return array           Policy transactions
     */
    public function createPolicyTransactions($policies)
    {
        $viewPolicy = [
            'type' => 'view',
            'value' => $policies['view'],
        ];
        $editPolicy = [
            'type' => 'edit',
            'value' => $policies['edit'],
        ];

        return [
            $viewPolicy,
            $editPolicy,
        ];
    }

    /**
     * Creates a priority transaction. Priority transactions expect strings,
     * not integers.
     *
     * @param  array $issue Issue details
     *
     * @return array        Priority transaction
     */
    public function createPriorityTransaction($issue)
    {
        if (!isset($issue['priority']) || empty($issue['priority'])) {
            return [];
        }

        $prio = $issue['priority']['name'];
        if (!array_key_exists($prio, $this->priority_map)) {
            printf(
                'We could not find a matching priority for your priority "%s"!'
                . PHP_EOL
                . '> ',
                $prio
            );
            $i = 0;
            foreach ($this->priority_map as $label => $value) {
                printf(
                    '[%d] – %s' . PHP_EOL,
                    $i++,
                    $label
                );
            }

            $selected = $this->prompt(
                sprintf(
                    'Please select a priority to use for %s',
                    $prio
                )
            );
            $weigths = array_values($this->priority_map);
            $this->priority_map[$prio] = $weights[$selected];
        }

        return [
            'type' => 'priority',
            'value' => (string) $this->priority_map[$prio], // Conduit expects a string o_O
        ];
    }

    /**
     * Create a transaction for subscribers
     *
     * @param  array $issue Issue details
     *
     * @return array        Transaction
     */
    public function createSubscriberTransaction($issue)
    {
        if (!isset($issue['watchers']) || empty($issue['watchers'])) {
            return [];
        }

        $subscribers = $this->watchersToSubscribers($issue['watchers']);

        if (!empty($subscribers)) {
            return [
                'type' => 'subscribers.set',
                'value' => $subscribers,
            ];
        }
    }

    /**
     * Retrieve Phabricator PHIDs for users in redmine watcher list
     *
     * @param  array $redmine_watchers List of users watching a given Redmine issue
     *
     * @return array                   List of phabricator user PHIDs
     */
    public function watchersToSubscribers($redmine_watchers)
    {
        $watchers = [];
        foreach ($redmine_watchers as $watcher) {
            if (!isset($watcher['name']) || empty($watcher['name'])) {
                continue;
            }
            $watchers[] = $watcher['name'];
        }
        return $this->getPhabricatorUserPhid($watchers);
    }

    /**
     * Since all comments will be created under the user
     * whose API token we're using, we cannot assign each
     * individual comment to the original author.
     * We therefore prefix the comments with their original author's names
     *
     * @param  array $issue Redmine issue
     *
     * @return array        A set of transactions of comments
     */
    public function createCommentTransactions($issue)
    {
        if (!isset($issue['journals'])) {
            return [];
        }

        $transactions = [];
        foreach ($issue['journals'] as $journal) {
            if (!isset($journal['notes']) || empty($journal['notes'])) {
                continue;
            }
            $timestamp = strtotime($journal['created_on']);
            $comment = sprintf(
                "On %s, %s wrote:\n %s",
                date('l, F jS Y H:i:s', $timestamp),
                $journal['user']['name'],
                $this->convertToQuote(
                    $this->convertFromRedmine($journal['notes'])
                )
            );

            if (!empty($journal['details'])) {
                $comment .= PHP_EOL . 'and:' . PHP_EOL . implode(
                    PHP_EOL,
                    $this->recountStory($journal['details'])
                );
            }

            $transactions[] = [
                'type' => 'comment',
                'value' => $comment,
            ];
        }
        return $transactions;
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

    public function convertToQuote($text)
    {
        return sprintf('> %s', preg_replace("/[\n\r]/", "\n> ", $text));
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
                switch ($action['name']) {
                    case 'status_id':
                        return ' - changed task status';
                        break;
                    case 'done_ratio':
                        return sprintf(
                            ' - changed done from %d%% to %d%%',
                            $action['old_value'],
                            $action['new_value']
                        );
                        break;
                    case 'priority_id':
                        return sprintf(
                            ' - %s the task\'s priority',
                            $action['new_value'] > $action['old_value'] ? 'Raised' : 'Lowered'
                        );
                        break;
                    case 'assigned_to_id':
                        // todo
                        // only has new_value!
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
            } elseif ($action['property'] === 'cf') {
                if (isset($action['old_value'])) {
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
     * Create status transaction
     *
     * @param  array $issue Redmine issue detail
     *
     * @return array        Status transaction
     */
    public function createStatusTransaction($issue)
    {
        $status = $issue['status']['name'];

        if (!array_key_exists($status, $this->status_map)) {
            printf(
                'We could not find a matching key for the status "%s"!' . "\n",
                $status
            );

            $stati = array_unique(array_values($this->status_map));
            $i = 0;
            foreach ($stati as $available) {
                printf(
                    '[%d] – %s' . PHP_EOL,
                    $i++,
                    $available
                );
            }

            $selected = $this->prompt('Select a status to use');
            $values = array_values($this->status_map);
            if (array_key_exists($selected, $values)) { // if $select < sizeof($values)
                $this->status_map[$status] = $values[$selected];
            } elseif (in_array($selected, $values)) {
                $this->status_map[$status] = $selected;
            } else {
                return $this->createStatusTransaction($issue);
            }
        }

        return [
            'type' => 'status',
            'value' => $this->status_map[$status],
        ];
    }

    /**
     * Upload file attachments, if any and add them to the
     * tasks' description.
     *
     * @param  array $details      Task details
     * @param  String $description Task description
     *
     * @return array               Transaction partial
     */
    public function createDescriptionTransaction($issue, $policies, $task = null)
    {
        $description = isset($issue['description']) ? $this->convertFromRedmine($issue['description']) : '';
        $file_ids = $this->uploadFiles($issue, $policies['view']);

        if (empty($file_ids)
            && (!empty($task) && $description === $task['description'])
        ) {
            return [];
        }

        if (!empty($file_ids)) {
            $files = implode(' ', $file_ids);
            $description = sprintf("%s\n\n%s", $description, $files);
        }

        return [
            'type' => 'description',
            'value' => $description,
        ];
    }

    /**
     * Creates a title transaction for new tasks or if the
     * titles have diverged in redmine and phabricator
     *
     * @param  array $issue  Redmine issue details
     * @param  array $task   Empty array or retrieved phabricator task
     *
     * @return array|void    A title transaction or nothing
     */
    public function createTitleTransaction($issue, $task)
    {
        if (empty($task) || $task['title'] !== $issue['subject']) {
            return [
                'type' => 'title',
                'value' => $issue['subject'],
            ];
        }
    }

    /**
     * Returns a transaction that sets the owner/assignee of a
     * maniphest task.
     *
     * @param  String $owner Owner PHID
     *
     * @return array        Transaction array
     */
    public function createOwnerTransaction($owner)
    {
        return [
            'type' => 'owner',
            'value' => $owner,
        ];
    }

    /**
     * Creates a transaction to assign this task to a
     * Phabricator project.
     *
     * @param  String $project_phid PHID of the project this task should be assigned to
     * @return array                Project transaction
     */
    public function createProjectTransaction($project_phid)
    {
        return [
            'type' => 'projects.set',
            'value' => [$project_phid],
        ];
    }
}
