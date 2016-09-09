<?php
/**
 * ReMaIm – Redmine to Phabricator Importer
 *
 * @package Ttf\Remaim
 *
 * @version  0.1.1 Short Circuit
 * @since    0.0.1 First public release
 *
 * @author  Jonathan Jin <jonathan@tentwentyfour.lu>
 * @author  David Raison <david@tentwentyfour.lu>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 */

namespace Ttf\Remaim;

use Pimple\Container;
use Redmine\Api\Issue;

use Ttf\Remaim\Exception\NoIssuesFoundException;

class Wizard
{
    use Traits\Phabricator;
    use Traits\Transactions;
    use Traits\FileManager;
    use Traits\ProjectList;
    use Traits\MarkupConverter;

    private $phabricator_users = [];
    private $config;
    private $redmine;
    private $conduit;
    private $priority_map;
    private $status_map;
    private $redmine_project;

    /**
     * Initialize Migration Wizard
     *
     * @param array           $config  Config read from YAML file
     * @param \Redmine\Client $redmine Instance of the Redmine API Client
     * @param \ConduitClient  $conduit Instance of ConduitClient
     */
    public function __construct(Container $c)
    {
        $this->container = $c;
        $this->config = $c['config'];
        $this->conduit = $c['conduit'];
        $this->redmine = $c['redmine'];
        $this->priority_map = $this->config['priority_map'];
        try {
            $this->status_map = $this->fetchPhabricatorStati();
        } catch (\HTTPFutureCURLResponseStatus $e) {
            fwrite(
                STDERR,
                sprintf(
                    PHP_EOL . 'I am unable to connect to %s. ' . PHP_EOL
                    . 'There was an error resolving the server hostname. Check that you are connected to the internet and that DNS is correctly configured.' . PHP_EOL . PHP_EOL,
                    $this->config['phabricator']['host']
                )
            );
            exit(1);
        }
    }

    /**
     * Main entry point for the Wizard, triggered by import.php
     *
     * @return void
     */
    public function run($resume = false)
    {
        print(
            " __      __          ___
/\ \  __/\ \        /\_ \
\ \ \/\ \ \ \     __\//\ \     ___    ___     ___ ___      __
 \ \ \ \ \ \ \  /'__`\\ \ \   /'___\ / __`\ /' __` __`\  /'__`\
  \ \ \_/ \_\ \/\  __/ \_\ \_/\ \__//\ \L\ \/\ \/\ \/\ \/\  __/
   \ `\___x___/\ \____\/\____\ \____\ \____/\ \_\ \_\ \_\ \____\
    '\/__//__/  \/____/\/____/\/____/\/___/  \/_/\/_/\/_/\/____/


 __               ____                                                 __
/\ \__           /\  _`\                                __            /\ \
\ \ ,_\   ___    \ \ \L\ \     __    ___ ___      __   /\_\    ___ ___\ \ \
 \ \ \/  / __`\   \ \ ,  /   /'__`\/' __` __`\  /'__`\ \/\ \ /' __` __`\ \ \
  \ \ \_/\ \L\ \   \ \ \\ \ /\  __//\ \/\ \/\ \/\ \L\.\_\ \ \/\ \/\ \/\ \ \_\
   \ \__\ \____/    \ \_\ \_\ \____\ \_\ \_\ \_\ \__/.\_\\ \_\ \_\ \_\ \_\/\_\
    \/__/\/___/      \/_/\/ /\/____/\/_/\/_/\/_/\/__/\/_/ \/_/\/_/\/_/\/_/\/_/
                                                                              "

            . PHP_EOL
        );
        try {
            if ($resume) {
                print(
                    'You have selected RESUME mode.' . PHP_EOL .
                    'Tell me, why exactly did you have to leave in a hurry and abort the previous run?' . PHP_EOL . PHP_EOL
                );
            }
            print('Attempting to connect to good ol\' Redmine... ');
            $this->redmine->assertConnectionToRedmine();
            print('success!' . PHP_EOL);
            print(
                'Stand by while we are retrieving a list of projects from your Redmine instance...' . PHP_EOL
            );
            $redmine_project = $this->redmine->show(
                $this->selectProject($this->redmine->listProjects())
            );
            $this->redmine_project = $redmine_project;

            $tasks = $this->redmine->getIssuesForProject($redmine_project);

            $phabricator_project = $this->selectOrCreatePhabricatorProject(
                $redmine_project
            );

            $policies = $this->definePolicies($this->lookupGroupProjects());
            $this->presentSummary(
                $redmine_project,
                $phabricator_project,
                $tasks,
                $policies
            );

            print('Working...' . PHP_EOL);
            $results = $this->migrateIssues(
                $tasks['issues'],
                $phabricator_project,
                $policies,
                $resume
            );
            printf(
                '%d tickets successfully migrated or updated!' . PHP_EOL,
                sizeof($results)
            );
            if (strtolower($this->prompt('Import another project? [y/N]')) === 'y') {
                $this->run();
            }
        } catch (NoIssuesFoundException $e) {
            if ('y' === strtolower($this->prompt(
                sprintf(
                    'There were no issues found on the selected project with ID %d' . PHP_EOL
                    . 'Would you like to continue with another project?' . PHP_EOL
                    . 'Select [y] to continue, [N] to exit',
                    $redmine_project
                )
            ))) {
                $this->run();
            } else {
                print('Bye bye! See you soon!' . PHP_EOL);
            }
        } catch (\Exception $e) {
            die(
                sprintf(
                    'Arrrgh… we\'re really sorry but something went a little haywire here.' . PHP_EOL
                    . 'Use the following information to help us fix it? Pretty please?' . PHP_EOL . PHP_EOL
                    . 'Exception message: %s' . PHP_EOL
                    . 'Exception trace:' . PHP_EOL
                    . '%s' . PHP_EOL,
                    $e->getMessage(),
                    $e->getTraceAsString()
                )
            );
        }
    }

    /**
     * Present a summary before asking the user to confirm their choices
     *
     * @param  array $redmine_project     Details about redmine project (source)
     * @param  array $phabricator_project Details about phabricator project (destination)
     * @param  array $tasks               Details about issues to be migrated
     *
     * @return Bool                       True if user accepts the summary
     */
    public function presentSummary(
        $redmine_project,
        $phabricator_project,
        $tasks,
        $policies
    ) {
        $project_detail = $this->redmine->getProjectDetails($redmine_project);

        printf(
            PHP_EOL . PHP_EOL .
            '####################' . PHP_EOL .
            '# Pre-flight check #' . PHP_EOL .
            '####################' . PHP_EOL .
            'Redmine project named "%s" with ID %s.' . PHP_EOL .
            'Target phabricator project named "%s" with ID %s.' . PHP_EOL .
            'View policy: %s, Edit policy: %s' . PHP_EOL,
            $project_detail['project']['name'],
            $redmine_project,
            $phabricator_project['name'],
            $phabricator_project['id'],
            $policies['view'],
            $policies['edit']
        );

        $answer = $this->prompt(
            sprintf(
                '%d tickets to be migrated!' . PHP_EOL . PHP_EOL
                . 'OK to continue? [y/N]',
                $tasks['total_count'][0]
            )
        );

        if (!($answer == 'y' || $answer == 'Y')) {
            die('KTHXBAI! Please visit again soon!'. PHP_EOL);
        }
        return true;
    }


    /**
     * Act on the choice that was made during selectOrCreatePhabricatorProject()
     *
     * @param  String $choice          Choice made by user
     * @param  array  $redmine_project Array describing the selected redmine project
     *
     * @return array                   Array describing and identifying the phabricator project to migrate to.
     */
    public function actOnChoice($choice, $redmine_project)
    {
        switch ($choice) {
            case 'q':
                print('Bye bye!' . PHP_EOL);
                exit(0);
            case '':
                $projects = $this->getAllPhabricatorProjects();
                ksort($projects);
                $project_id = $this->selectProject($projects, true);

                if ('' === $project_id) {
                    return $this->selectOrCreatePhabricatorProject($redmine_project);
                } elseif (array_key_exists($project_id, $projects['projects'])) {
                    $query = [
                        'ids' => [$project_id]
                    ];
                    $verb = 'Selected';
                } else {
                    printf(
                        'Sorry, if a project with id %d exists, you don\'t seem to have access to it. Please check your permissions and the id you specified and try again.' . PHP_EOL,
                        $project_id
                    );
                    return $this->selectOrCreatePhabricatorProject($redmine_project);
                }
                break;
            case '0':
                $policies = $this->definePolicies($this->lookupGroupProjects());
                $detail = $this->redmine->getProjectDetails($redmine_project);
                $phab_members = $this->getPhabricatorUserPhid(
                    $this->redmine->getProjectMembers($redmine_project)
                );
                $project = $this->createNewPhabricatorProject(
                    $detail,
                    $phab_members,
                    $policies
                );

                $query = [
                    'ids' => [
                        $project['object']['id']
                    ]
                ];
                $verb = 'Created';
                break;
            default:
                if (is_numeric($choice)) {
                    $query = [
                        'ids' => [$choice],
                    ];
                } elseif (is_string($choice)) {
                    $query = [
                        'slugs' => [$choice],
                    ];
                }
                $verb = 'Found';
                break;
        }

        if (!isset($query)) {
            throw new \RuntimeException(
                'Failed to build a project lookup query to find a Phabricator project to migrate to.'
            );
        }

        $project = $this->findPhabricatorProject($query);
        if ($project) {
            printf(
                '%s project "%s" with PHID %s' . "\n",
                $verb,
                $project['name'],
                $project['phid']
            );
            return $project;
        }
    }


    /**
     * Allow the user to select a group that will
     * be used for view and edit policies for both
     * projects and tasks creaed in maniphest.
     *
     * @param  array $groups Groups found by self::lookupGroupPojects()
     *
     * @return array         Array containing PHIDs for view and edit policies
     */
    public function definePolicies($groups)
    {
        print(
            PHP_EOL
            . 'Let\'s set some policies on the new tasks, shall we?' . PHP_EOL
            . 'Here are the group projects that I found:'
            . PHP_EOL . PHP_EOL
        );
        $i = 0;
        foreach ($groups['data'] as $group) {
            printf(
                "[%d] =>\t[ID]: %d \n\t[Name]: %s\n",
                $i++,
                $group['id'],
                $group['fields']['name']
            );
        }
        $index = $this->selectIndexFromList(
            'Select a group to get view and edit permissions',
            $i
        );
        $groupproject = $groups['data'][$index];
        return [
            'view' => $groupproject['phid'],
            'edit' => $groupproject['phid'],
        ];
    }

    /**
     * Asks the user to select one of the listed projects.
     *
     * @param  array $projects List of projects and total count retrieved
     * @return String          User input
     */
    private function selectProject($projects, $can_return = false)
    {
        printf(
            '%d total projects retrieved.'
            . PHP_EOL . PHP_EOL,
            $projects['total_count']
        );
        foreach ($projects['projects'] as $toplevel) {
            print($this->representProject($toplevel));
        }
        print(PHP_EOL);

        $message = 'Please select (type) a project ID';
        $message .= ($can_return) ? ' or leave empty to go back to the previous step' : '';

        return $this->selectIndexFromList(
            $message,
            $projects['highest'],
            $projects['lowest'],
            $can_return
        );
    }

    /**
     * Prompt the user for a response
     *
     * @param  String $question Message or question to display
     *
     * @return String           Reponse given by user
     */
    private function prompt($question)
    {
        printf(
            '%s:' . PHP_EOL . '> ',
            $question
        );
        $fp = fopen('php://stdin', 'r');
        $response = trim(fgets($fp, 1024));
        fclose($fp);
        return $response;
    }

    /**
     * Prompt user to select an index from a list
     *
     * @param  String  $message Message or question to display
     * @param  Integer $max     Highest index that can be selected
     * @param  Integer $min     Lowest index that can be selected
     *
     * @return String           User input
     */
    public function selectIndexFromList($message, $max, $min = 0, $allow_empty = false)
    {
        $selectedIndex = $this->prompt($message);
        if (!$allow_empty && (!is_numeric($selectedIndex) || $selectedIndex > $max)) {
            printf(
                'You must select a value between %d and %d' . PHP_EOL,
                $min,
                $max
            );
            return $this->selectIndexFromList($message, $max, $min, $allow_empty);
        }
        return $selectedIndex;
    }

    /**
     * Migrate issues
     *
     * @param  array  $issues     Array of issues to be migrated
     * @param  String $ph_project Phabricator project PHID
     * @param  array  $policies   Policies to be applied to each new task
     *
     * @return array              Array of tasks created in phabricator
     */
    public function migrateIssues(
        $issues,
        $ph_project,
        $policies,
        $resume = false
    ) {
        return array_map(function ($issue) use ($ph_project, $policies, $resume) {
            $details = $this->redmine->getIssueDetail($issue['id']);
            $owner = $this->grabOwnerPhid($details['issue']);
            return $this->createManiphestTask(
                $details['issue'],
                $owner,
                $ph_project['phid'],
                $policies,
                $resume
            );
        }, $issues);
    }

    /**
     * Looks for a pre-existing task and creates an array of transactions based
     * on the redmine issue. Then calls createOrUpdatePhabTicket() to create
     * or update the task.
     *
     * @param  array  $issue        Issue details
     * @param  string $owner        PHID identifying a potential task assignee
     * @param  string $project_phid PHID identifying a project in Phabricator
     * @param  array  $policies     List of policies to apply to the task
     * @param  bool   $resume       Whether we wish to resume a previous run and ignore already existing tasks
     *
     * @return array                Details of maniphest.edit operation
     */
    public function createManiphestTask(
        $issue,
        $owner,
        $project_phid,
        $policies,
        $resume = false
    ) {
        $task = $this->findExistingTask($issue, $project_phid, $resume);

        if (false === $task) {
            printf(
                'Skipping existing issue %d - %s' . PHP_EOL,
                $issue['id'],
                $issue['subject']
            );
            return;
        }

        printf(
            '%s issue %d - "%s"...' . PHP_EOL,
            !empty($task) ? 'Updating' : 'Migrating',
            $issue['id'],
            $issue['subject']
        );

        $transactions = $this->assembleTransactionsFor(
            $project_phid,
            $issue,
            $policies,
            $task,
            $owner
        );

        return $this->createOrUpdatePhabTicket($transactions, $task);
    }

    /**
     * Assembles a collection of transactions to be applied to each
     * issue that will be migrated.
     *
     * @param  string  $project_phid     Phabricator Project PHID
     * @param  array   $issue            Issue details
     * @param  array   $policies         View and edit policies
     * @param  array   $task             Maniphest task, if one already exists
     * @param  boolean $assignee         Future owner of the task
     *
     * @return array                    Transaction array
     */
    public function assembleTransactionsFor(
        $project_phid,
        $issue,
        $policies,
        $task = [],
        $assignee = false
    ) {
        $transactions = [];
        $transactions[] = $this->createProjectTransaction($project_phid);
        $transactions[] = $this->createTitleTransaction(
            $issue,
            $task
        );
        $transactions[] = $this->createDescriptionTransaction(
            $issue,
            $policies,
            $task
        );
        $transactions[] = $this->createStatusTransaction($issue);
        $transactions[] = $this->createSubscriberTransaction($issue);
        $transactions[] = $this->createPriorityTransaction($issue);

        if ($assignee && !empty($assignee)) {
            $transactions[] = $this->createOwnerTransaction($assignee);
        }

        $transactions = array_merge(
            $transactions,
            $this->createCommentTransactions($issue)
        );

        $transactions = array_merge(
            $transactions,
            $this->createPolicyTransactions($policies)
        );

        return array_values(
            array_filter($transactions, function ($transaction) {
                return !empty($transaction);
            })
        );
    }

    /**
     * Grab the PHID for a user that will become the Owner of the
     * task.
     *
     * @todo Add support for redmine groups
     *
     * @param  array $issue Issue detail from redmine
     *
     * @return String       Owner PHID
     */
    public function grabOwnerPhid($issue)
    {
        if (!isset($issue['assigned_to'])) {
            return false;
        }

        $phab_ids = $this->getPhabricatorUserPhid(
            [$issue['assigned_to']['name']]
        );

        return !empty($phab_ids) ? $phab_ids[0] : [];
    }
}
