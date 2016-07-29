<?php


namespace Ttf\Remaim\Traits;

trait Transactions
{
    public function transactPolicy($details, $constraints)
    {
        $i = 0;
        foreach ($constraints['data'] as $constraint) {
            printf(
                "[%d] =>\t[ID]: T%d \n\t[Name]: %s\n",
                $i++, 
                $constraint['id'],
                $constraint['fields']['name']
            );
        }
        $index = $this->selectTicketPhidFromDuplicates();
        $groupproject = $constraints['data'][$index];
        $selected_projectphid = $groupproject['phid'];

        $viewPolicy = [
            'type' => 'view',
            'value' => $selected_projectphid,
        ];  
        $editPolicy = [
            'type' => 'edit',
            'value' => $selected_projectphid,
        ];

        $transacts = [
            $viewPolicy,
            $editPolicy,
        ];

        return $transacts;
    }

    public function transactPriority($details)
    {
        $prio = $details['issue']['priority']['name'];
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

    public function transactSubscriber($details)
    {
        $subscribers = $this->watchersToSubscribers($this->conduit, $details['issue']['watchers']);
        if (!empty($subscribers)) {
            $transactions = [
                'type' => 'subscribers.set',
                'value' => $subscribers,
            ];
            return $transactions;
        }
    }

    public function transactComments($details)
    {
        foreach ($details['issue']['journals'] as $journal) {
            if (!isset($journal['notes']) || empty($journal['notes'])) {
            continue;
            }
            $comment = sprintf(
            "%s originally wrote:\n> %s",
            $journal['user']['name'],
            $journal['notes']
            );

            $transactions = [
                'type' => 'comment',
                'value' => $comment,
            ];
            return $transactions;
        }
    }

    public function transactStatus($details, $status_map)
    {
        // query phabricator => save to list
        $status = $details['issue']['status']['name'];
        $key = array_search($status, $status_map);

        if (!$key) {
            printf('We could not find a matching key for your status "%s"!' . "\n> ", $status);
            foreach ($status_map as $key => $value) {
                printf("%s\n", $key);
            }
            printf(
                'Press [1] to add "%s" to the map_list; [2] if you want to give it a value from the map_list',
                $status
            );
            $fp = fopen('php://stdin', 'r');
            $map_check = trim(fgets($fp, 1024));
            fclose($fp);

            if ($map_check == '1') {
                $status_map[] = $status;
            }
            elseif ($map_check == '2') {
                printf('Enter the wished value!');
                $fp = fopen('php://stdin', 'r');
                $new_value = trim(fgets($fp, 1024));
                fclose($fp);
                $status = $new_value;

            }
        }

        // this does not work
        // save new mapping to list

        $transactions = [
            'type' => 'status',
            'value' => $status,
        ];
        return $transactions;
    }

    public function transactFiles($details, $description)
    {
        $file_ids = [];
        foreach ($details['issue']['attachments'] as $attachment) {
            $url = preg_replace(
                '/http(s?):\/\//',
                sprintf(
                    'https://%s:%s@',
                    $this->config['redmine']['user'],
                    $this->config['redmine']['password']
                ),
                $attachment['content_url']
            );

            $encoded = base64_encode(file_get_contents($url));
            $api_parameters = [
                'name' => $attachment['filename'],
                'data_base64' => $encoded
               // 'viewPolicy' => todo!
            ];
            $file_phid = $this->conduit->callMethodSynchronous('file.upload', $api_parameters);
            $api_parameters = array(
              'phid' => $file_phid,
            );
            $result = $this->conduit->callMethodSynchronous('file.info', $api_parameters);
            $file_ids[] = sprintf('{%s}', $result['objectName']);
        }

        $files = implode(' ', $file_ids);
        $transactions = [
            'type' => 'description',
            'value' => sprintf("%s\n\n%s", $description, $files)
        ];
        return $transactions;
    }

    public function transactTitle($details, $ticket)
    {
        // * Is $task identical/similar to $ticket?
        // DR: or !empty $task?
        if ($ticket['title'] !== $details['issue']['subject']) {
            return [
                'type' => 'title',
                'value' => $details['issue']['subject'],
            ];
        }

    }

    public function transactOwnerPhid($owner)
    {
        return [
            'type' => 'owner',
            'value' => $owner,
        ];  
    }

    public function transactPhabProjectPhid($phabricator_project)
    {
        return [
            'type' => 'projects.set',
            'value' => $phabricator_project,
        ];  
    }
}