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

        $subscribers = $this->watchersToSubscribers(
            $this->conduit,
            $issue['watchers']
        );

        if (!empty($subscribers)) {
            $transactions = [
                'type' => 'subscribers.set',
                'value' => $subscribers,
            ];
            return $transactions;
        }
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
            var_dump($journal); exit;
            if (!isset($journal['notes']) || empty($journal['notes'])) {
                continue;
            }
            $comment = sprintf(
                "%s originally wrote:\n> %s",
                $journal['user']['name'],
                $journal['notes']
            );

            $transactions[] = [
                'type' => 'comment',
                'value' => $comment,
            ];
        }
        return $transactions;
    }

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