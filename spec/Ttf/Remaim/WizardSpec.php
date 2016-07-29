<?php
/**
 * PhpSpec spec for the Redmine to Maniphest Importer
 */

namespace spec\Ttf\Remaim;

use PhpSpec\ObjectBehavior;
use Prophecy\Argument;
use Mockery as m;

use Ttf\Remaim\Wizard;  // System under test

use Redmine\Client;
use Redmine\Api\Project;
use Redmine\Api\Issue;

require_once '/usr/share/libphutil/src/__phutil_library_init__.php';

class WizardSpec extends ObjectBehavior
{

    private $conduit;   // mock of ConduitClient

    /**
     * We're using Mockery for mocking ConduitClient, which is marked as final.
     * See http://docs.mockery.io/en/latest/reference/index.html for Reference.
     *
     * "The class \ConduitClient is marked final and its methods cannot be replaced. Classes marked final can be passed in to \Mockery::mock() as instantiated objects to create a partial mock, but only if the mock is not subject to type hinting checks.
     *
     * @return void
     */
    public function let(Client $redmine, Project $project)
    {
        $config = [
            'redmine' => [
            ],
            'phabricator' => [
            ],
        ];
        // Proxied partial mock, see http://docs.mockery.io/en/latest/reference/partial_mocks.html#proxied-partial-mock
        $this->conduit = m::mock(new \ConduitClient('https://localhost'));
        $this->conduit
        ->shouldReceive('callMethodSynchronous')
        ->with('maniphest.querystatuses', [])
        ->times(1)
        ->andReturn(['statusMap' => [
            'open' => 'Open',
            'resolved' => 'Resolved',
        ]]);

        $this->beConstructedWith($config, $redmine, $this->conduit);
    }

    public function letGo()
    {
        m::close();
    }

    function it_is_initializable()
    {
        $this->shouldHaveType(Wizard::class);
    }

    function it_exits_with_an_exception_if_it_cannot_connect_to_redmine(Client $redmine, Project $project)
    {
        $redmine->api('project')->willReturn($project);
        $project->listing()->shouldBeCalled();
        $this->shouldThrow('\RuntimeException')->duringTestConnectionToRedmine();
    }

    function it_returns_true_if_it_can_connect_to_redmine(Client $redmine, Project $project)
    {
        $redmine->api('project')->willReturn($project);
        $project->listing()->shouldBeCalled();
        $project->listing()->willReturn([
            'Website' => [1]
        ]);
        $this->testConnectionToRedmine()->shouldReturn(true);
    }

    function it_throws_an_exception_if_redmine_returns_an_empty_value(Client $redmine, Project $project)
    {
        $redmine->api('project')->willReturn($project);
        $project->listing()->shouldBeCalled();
        $project->listing()->willReturn();
        $this->shouldThrow('\RuntimeException')->duringTestConnectionToRedmine();
    }

    function it_throws_an_exception_if_redmine_returns_an_non_array_value(Client $redmine, Project $project)
    {
        $redmine->api('project')->willReturn($project);
        $project->listing()->shouldBeCalled();
        $project->listing()->willReturn(1);
        $this->shouldThrow('\RuntimeException')->duringTestConnectionToRedmine();
    }

    function it_is_able_to_look_up_a_phabricator_project_by_its_id()
    {
        $lookup = [
            'ids' => [1]
        ];
        $project_array = [
            'phid' => 'test-phid',
            'name' => 'test-project-name',
        ];
        $query_result = [
            'data' => [$project_array],
        ];
        $this->conduit
        ->shouldReceive('callMethodSynchronous')
        ->with('project.query', $lookup)
        ->times(1)
        ->andReturn($query_result);
        $this->findPhabProjectWithIdSlug($lookup)->shouldReturn($project_array);
    }

    function it_returns_a_structured_list_of_projects(Client $redmine, Project $project)
    {
        $redmine->api('project')->willReturn($project);
        $project->all(['limit' => 1024])->shouldBeCalled();
        $project->all(['limit' => 1024])->willReturn([
            'projects' => [
                [
                    'id' => 5,
                    'name' => 'Project one',
                    'identifier' => 'project_one',
                    'description' => 'The first project',
                    'status' => 1,
                    'created_on'  => "2013-05-16T18:40:18Z",
                    'updated_on' => "2013-05-16T18:40:18Z"
                ],
                [
                    'id' => 6,
                    'name' => 'Project two',
                    'identifier' => 'project_two',
                    'description' => 'The second project',
                    'status' => 1,
                    'parent' => [
                        'id' => 5
                    ],
                    'created_on'  => "2013-05-16T18:40:18Z",
                    'updated_on' => "2013-05-16T18:40:18Z"
                ],
            ],
            'total_count' => [2],
        ]);

        $project_array = [
            [
                'id' => 5,
                'name' => 'Project one',
                'identifier' => 'project_one',
                'description' => 'The first project',
                'status' => 1,
                'created_on'  => "2013-05-16T18:40:18Z",
                'updated_on' => "2013-05-16T18:40:18Z",
                'parent' => [
                    'id' => 0
                ],
                'children' => [
                    [
                        'id' => 6,
                        'name' => 'Project two',
                        'identifier' => 'project_two',
                        'description' => 'The second project',
                        'status' => 1,
                        'parent' => [
                            'id' => 5
                        ],
                        'created_on'  => "2013-05-16T18:40:18Z",
                        'updated_on' => "2013-05-16T18:40:18Z",
                        'children' => []
                    ],
                ],
            ],
        ];

        $this->listRedmineProjects()->shouldReturn($project_array);
    }

    function it_returns_the_projects_id_and_name()
    {
        $project = [
            'id' => 5,
            'name' => 'Tests',
            'description' => 'Test has to ignore me'
        ];
        $this->representProject($project)->shouldReturn("[5 – Tests]\n");
    }

    function it_throws_an_exception_if_no_tasks_are_found(Client $redmine, Issue $issue)
    {
        $redmine->api('issue')->willReturn($issue);
        $issue->all([
            'project_id' => 1,
            'limit' => 1024,
        ])->willReturn(['issues' => []]);

        $issue->all([
            'project_id' => 1,
            'limit' => 1024,
        ])->shouldBeCalled();

        $this->shouldThrow('\RuntimeException')->during('getIssuesForProject', [1]);
    }

    function it_throws_an_exception_if_looking_up_tasks_failed(Client $redmine, Issue $issue)
    {
        $redmine->api('issue')->willReturn($issue);
        $issue->all([
            'project_id' => 1,
            'limit' => 1024,
        ])->willReturn(false);

        $issue->all([
            'project_id' => 1,
            'limit' => 1024,
        ])->shouldBeCalled();
        $this->shouldThrow('\RuntimeException')->during('getIssuesForProject', [1]);
    }

    function it_returns_true_if_a_task_is_found(Client $redmine, Issue $issue)
    {
        $redmine->api('issue')->willReturn($issue);

        $issue->all([
            'project_id' => 1,
            'limit' => 1024,
        ])->willReturn([
            'issues' => [
                [
                    'id' => 1,
                    'project' => [
                        'id' => 1,
                        'name' => 'Test',
                    ],
                    'tracker' => [
                        'id' => 1,
                        'name' => 'Bug',
                    ],
                    'status' => [
                        'id' => 1,
                        'name' => 'New',
                    ],
                    'priority' => [
                        'id' => 4,
                        'name' => 'Normal',
                    ],
                ]
            ]
        ]);

        $issue->all([
            'project_id' => 1,
            'limit' => 1024,
        ])->shouldBeCalled();
        $this->getIssuesForProject(1)->shouldReturn([
            [
                'id' => 1,
                'project' => [
                    'id' => 1,
                    'name' => 'Test',
                ],
                'tracker' => [
                    'id' => 1,
                    'name' => 'Bug',
                ],
                'status' => [
                    'id' => 1,
                    'name' => 'New',
                ],
                'priority' => [
                    'id' => 4,
                    'name' => 'Normal',
                ],
            ],
        ]);
    }

    function it_caches_phabricator_user_lookups()
    {
        $lookupone = [
            'James',
            'Alfred',
            'John',
        ];

        $lookuptwo = [
            'James',
            'Alfred',
        ];

        $query_result = [
            [
                'phid' => 'phidone',
                'realName' => 'James',
            ],
            [
                'phid' => 'phidtwo',
                'realName' => 'Alfred',
            ],
            [
                'phid' => 'phidthree',
                'realName' => 'John',
            ],
        ];

        $methodoutcomeone = [
            'phidone',
            'phidtwo',
            'phidthree',
        ];

        $methodoutcometwo = [
            'phidone',
            'phidtwo',
        ];
        $this->conduit
        ->shouldReceive('callMethodSynchronous')
        ->with('user.query', ['realnames' => $lookupone])
        ->times(1)
        ->andReturn($query_result);

        $this->getPhabricatorUserPhid($lookupone)->shouldReturn($methodoutcomeone);
        $this->getPhabricatorUserPhid($lookuptwo)->shouldReturn($methodoutcometwo);
    }

    function it_generates_a_title_transaction_for_new_tasks()
    {
        $details = [
            'issue' => [
                'subject' => 'Test Subject',
                'attachments' => [],
                'status' => [
                    'id' => 1,
                    'name' => 'Resolved',
                ]
            ]
        ];
        $policies = [
            'view' => 'PHID-foobar',
            'edit' => 'PHID-barbaz',
        ];

        $this->assembleTransactionsFor(
            'PHID-random',
            $details,
            'A random description of a task',
            $policies
        )->shouldReturn([
            'type' => 'title',
            'value' => 'Test subject',
        ]);

    }

    function it_generates_a_title_transaction_if_the_title_has_changed()
    {

    }

    function it_does_not_generate_a_transaction_if_the_title_has_not_changed()
    {

    }

    function it_assembles_an_array_of_transactions_from_ticket_details()
    {

    }

    function it_filters_out_empty_transactions()
    {
        // $this->transactTitle()->willReturn([]);
        // $this->assembleTransactionsFor()->shouldReturn([]);
    }

    function xit_creates_a_new_task_if_no_match_is_found_in_phabricator()
    {
        $priority_map = [
            'Immediate' => 100, // unbreak now!
            'Urgent' => 100,    // unbreak now!
            'High' => 80,       // High
            'Normal' => 50,     // Normal
            'Low' => 25         // Low
            // Wishlist
        ];

        $tickets = [];
        $description = 'Hey testing this';
        $status_map = [];
        $phabricator_project = [
            'id' => 42,
            'phid' => 'test-phid',
            'name' => 'Foo project',
        ];

        $owner = [
            'name' => 'Foo Bar',
            'phid' => 'test-phid'
        ];

        $details = [
            'issue' => [
                'id' => 2541,
                'project' => [
                    'id' => 42,
                    'name' => 'Foo project',
                ],
                'tracker' => [
                    'id' => 1,
                    'name' => 'Bug',
                ],
                'status' => [
                    'id' => 3,
                    'name' => 'Resolved',
                ],
                'priority' => [
                    'id' => 4,
                    'name' => 'Urgent',
                ],
                'subject' => 'testsolving',
                'attachments' => [],
            ]
        ];

        // $api = [
        //     'title' => $details['issue']['subject'],
        //     'description' => $description,
        //     'ownerPHID' => $owner['phid'],
        //     'priority' => $priority_map[$details['issue']['priority']['name']],
        //     'projectPHIDs' => [
        //         $phabricator_project['phid'],
        //     ],
        // ];

        $result = [
            'title' => 'testsolving',
            'description' => 'Hey testing this',
            'ownerPHID' => 'test-phid',
            'priority' => 100,
            'projectPHIDs' => [
                'phab-phid'
            ],
        ];

        $this->conduit
        ->shouldReceive('callMethodSynchronous')
        ->with('maniphest.edit')
        ->times(1)
        ->andReturn($result);

        $this->createManiphestTask(
            $tickets,
            $details,
            $description,
            $owner,
            $phabricator_project,
            $policies
        )->shouldReturn($result);
    }

    // TODO: Repair the Status and Priority Transactions
    // function it_should_return_a_non_empty_ticket_with_the_updated_information()
    // {
    //     $priority_map = [
    //     'Immediate' => 100, // unbreak now!
    //     'Urgent' => 100,    // unbreak now!
    //     'High' => 80,       // High
    //     'Normal' => 50,     // Normal
    //     'Low' => 25         // Low
    //      // Wishlist
    //     ];

    //     $tickets = [
    //         'PHID-TASK-biummspek2k4372ciaud' => [
    //             'phid' => 'testphid',
    //             'ownerPHID' => 'Replace-my ownerPHID',
    //             'priority' => 'Urgent',
    //             'title' => 'Replace-my title',
    //             'description' => 'Replace-my description',
    //             'projectPHIDs' => [
    //                 'Replace-my projectPHID',
    //             ],
    //         ],
    //     ];

    //     $description = 'Hey testing this';

    //     $status_map = [
    //         'Urgent' => 8,
    //     ];

    //     $phabricator_project = [
    //         'id' => 34,
    //         'phid' => 'test-phid',
    //         'name' => '1024 Website',

    //     ];

    //     $owner = [
    //         'name' => 'Fufufo',
    //         'phid' => 'test-phid'
    //     ];

    //     $details = [
    //         'issue' => [
    //             'id' => 2541,
    //             'project' => [
    //                 'id' => 25,
    //                 'name' => 'Website',
    //             ],
    //             'tracker' => [
    //                 'id' => 1,
    //                 'name' => 'Bug',
    //             ],
    //             'status' => [
    //                 'id' => 3,
    //                 'name' => 8,
    //             ],
    //             'priority' => [
    //                 'id' => 4,
    //                 'name' => 'Low',
    //             ],
    //             'subject' => 'titlesolved',
    //             'journals' => [
    //                 'notes' => 'test-description',
    //             ],
    //             'watchers' => [],
    //             'attachments' => [],

    //         ]
    //     ];

    //     $result = [
    //         [
    //         'type' => 'title',
    //         'value' => 'titlesolved',
    //         ],
    //         [
    //         'type' => 'status',
    //         'value' => 'Urgent',
    //         ],
    //         [
    //         'type' => 'comment',
    //         'value' => 'test-description',
    //         ],
    //         [
    //         'type' => 'priority',
    //         'value' => 'Low',
    //         ],
    //     ];

    //     $this->createManiphestTask($priority_map, $tickets, $details, $description, $owner, $phabricator_project, $status_map)->shouldReturn($result);
    // }

    function xit_should_return_an_updated_phabticket()
    {
        $priority_map = [
        'Immediate' => 100, // unbreak now!
        'Urgent' => 100,    // unbreak now!
        'High' => 80,       // High
        'Normal' => 50,     // Normal
        'Low' => 25         // Low
         // Wishlist
        ];

        $ticket = [
            'phid' => 'testphid',
            'ownerPHID' => 'Replace-my ownerPHID',
            'priority' => 'Urgent',
            'title' => 'Replace-my title',
            'description' => 'Replace-my description',
            'projectPHIDs' => [
                'Replace-my projectPHID',
            ],
        ];

        $api = [
            'objectIdentifier' => 'test-phid',
            'transactions' => [
                ['type' => 'title',
                'value' => 'testsolving',
                ],
                [
                'type' => 'status',
                'value' => 'Normal',
                ],
                [
                'type' => 'comment',
                'value' => 'test-description',
                ],
                [
                'type' => 'subscribers.set',
                'value' => 'Jona',
                ],
                [
                'type' => 'priority',
                'value' => 'Immediate',
                ],
            ],
        ];

        $transactions = [
                ['type' => 'title',
                'value' => 'testsolving',
                ],
                [
                'type' => 'status',
                'value' => 'Normal',
                ],
                [
                'type' => 'comment',
                'value' => 'test-description',
                ],
                [
                'type' => 'subscribers.set',
                'value' => 'Jona',
                ],
                [
                'type' => 'priority',
                'value' => 'Immediate',
                ],
        ];

        $result = [
            'title' => 'testsolving',
            'status' => 'Normal',
            'comment' => 'test-description',
            'subscribers.set' => 'Jona',
            'Priority' => 'Immediate',

        ];

        $this->conduit
        ->shouldReceive('callMethodSynchronous')
        ->with('maniphest.edit', $api)
        ->times(1)
        ->andReturn($result);
        $this->updatePhabTicket($ticket, $transactions)->shouldReturn($result);
    }

}
