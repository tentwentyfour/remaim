<?php
/**
 * ReMaIm – Redmine to Phabricator Importer
 *
 * @package Ttf\Remaim
 * @version  0.0.1 First public release
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

    public function createPriorityTransaction($issue)
    {
        if (!isset($issue['priority']) || empty($issue['priority'])) {
            return [];
        }

        $prio = $issue['priority']['name'];
        $priority = $this->priority_map[$prio];
        if (!$priority) {
            printf('We could not find a matching priority for your priority "%s"!' . "\n> ", $prio);
            foreach ($this->priority_map as $priority2 => $value) {
                printf("%s\n", $priority2);
            }

            printf('Press [1] to add %s to the map_list; [2] if you want to give it a value from the map_list');
            $fp = fopen('php://stdin', 'r');
            $map_check = trim(fgets($fp, 1024));
            fclose($fp);

            if ($map_check == '1') {
                $this->priority_map = $prio;
            }
            elseif ($map_check == '2') {
                printf('Enter the wished value!');
                $fp = fopen('php://stdin', 'r');
                $new_value = trim(fgets($fp, 1024));
                fclose($fp);
                $prio = $new_value;

            }
        }

        $transactions = [
            'type' => 'priority',
            'value' => $prio,
        ];
        return $transactions;
    }

    public function createSubscriberTransaction($issue)
    {
        if (!isset($issue['watchers']) || empty($issue['watchers'])) {
            return [];
        }

        $subscribers = $this->watchersToSubscribers($issue['watchers']);

        if (!empty($subscribers)) {
            $transactions = [
                'type' => 'subscribers.set',
                'value' => $subscribers,
            ];
            return $transactions;
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
                date('r', $timestamp),
                $journal['user']['name'],
                $this->convertToQuote(
                    $this->convertFromRedmine($journal['notes'])
                )
            );

            if (!empty($journal['details'])) {
                $comment .= ' and ' . implode(
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
            switch ($action['name']) {
                case 'status_id':
                    return 'Changed task status';
                    break;
                case 'done_ratio':
                    return sprintf(
                        'Changed done from %d%% to %d%%',
                        $action['old_value'],
                        $action['new_value']
                    );
                    break;
                default:
                    return sprintf(
                        'Changed a custom field value from %s to %s',
                        $action['old_value'],
                        $action['new_value']
                    );
                    break;
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
            foreach ($stati as $available) {
                printf("%s\n", $available);
            }

            printf('Enter the desired value!' . "\n> ");
            $fp = fopen('php://stdin', 'r');
            $new_value = trim(fgets($fp, 1024));
            fclose($fp);
            $this->status_map[$status] = $new_value;
        }

        $transactions = [
            'type' => 'status',
            'value' => $this->status_map[$status],
        ];
        return $transactions;
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
        $description = isset($issue['description']) ? $issue['description'] : '';
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
