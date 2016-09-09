<?php
/**
 * PhpSpec spec for the Redmine to Maniphest Importer
 */

namespace spec\Ttf\Remaim;

use PhpSpec\ObjectBehavior;
use Prophecy\Argument;
use Mockery as m;
use phpmock\mockery\PHPMockery;

use Ttf\Remaim\Wizard;  // System under test

use Pimple\Container;

use Redmine\Client;
use Redmine\Api\Project;
use Redmine\Api\Issue;
use Redmine\Api\Membership;
use Redmine\Api\CustomField;

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
    public function let(Client $redmine, Project $project, CustomField $custom_fields)
    {
        date_default_timezone_set('UTC');

        $container = new Container();
        $container['redmine_client'] = function ($c) {
            return new Client;
        };

        $config = [
            'redmine' => [
                'user' => 'Hank',
                'password' => 'ImNotMoody',
                'protocol' => 'https',
            ],
            'phabricator' => [
                'host' => 'https://localhost',
            ],
            'priority_map' => [
                'Urgent' => 100,
                'Normal' => 50,
                'Low' => 25
            ]
        ];

        // $redmine->api('project')->willReturn($project);
        // $project->listing()->willReturn(['some' => 'array']);
        // $redmine->api('custom_fields')->willReturn($custom_fields);
        // $custom_fields->listing()->willReturn([
        //     'Billed' => 1,
        //     'Out of scope' => 2,
        // ]);

        // Proxied partial mock, see http://docs.mockery.io/en/latest/reference/partial_mocks.html#proxied-partial-mock
        $container['conduit'] = function ($c) {
            $conduit = m::mock(new \ConduitClient($config['phabricator']['host']));
            $conduit
            ->shouldReceive('callMethodSynchronous')
            ->with('maniphest.querystatuses', [])
            ->once()
            ->andReturn(['statusMap' => [
                'open' => 'Open',
                'resolved' => 'Resolved',
            ]]);
        };

        $this->beConstructedWith($container);
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
        $project->listing()->shouldBeCalled()->willReturn([]);
        $this->shouldThrow('\RuntimeException')->duringAssertConnectionToRedmine();
    }

    function it_returns_true_if_it_can_connect_to_redmine(Client $redmine, Project $project)
    {
        $redmine->api('project')->willReturn($project);
        $project->listing()->shouldBeCalled()->willReturn([
            'Website' => [1]
        ]);
        $this->assertConnectionToRedmine()->shouldReturn(true);
    }

    function it_throws_an_exception_if_redmine_returns_an_empty_value(Client $redmine, Project $project)
    {
        $redmine->api('project')->willReturn($project);
        $project->listing()->shouldBeCalled();
        $project->listing()->willReturn();
        $this->shouldThrow('\RuntimeException')->duringAssertConnectionToRedmine();
    }

    function it_throws_an_exception_if_redmine_returns_an_non_array_value(Client $redmine, Project $project)
    {
        $redmine->api('project')->willReturn($project);
        $project->listing()->shouldBeCalled();
        $project->listing()->willReturn(1);
        $this->shouldThrow('\RuntimeException')->duringAssertConnectionToRedmine();
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
        ->once()
        ->andReturn($query_result);
        $this->findPhabricatorProject($lookup)->shouldReturn($project_array);
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
            'total_count' => 2,
            'lowest' => 5,
            'highest' => 6,
            'projects' => [
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
                ]
            ],
        ];

        $this->listRedmineProjects()->shouldReturn($project_array);
    }

    function it_returns_the_redmine_projects_id_and_name()
    {
        $project = [
            'id' => 5,
            'name' => 'Tests',
            'description' => 'Test has to ignore me'
        ];
        $this->representProject($project)->shouldReturn("[5 – Tests]\n");
    }

    function it_looks_up_group_projects()
    {
        $groups = [
            'data' => [
                [
                    'id' => 23,
                    'type' => 'PROJ',
                    'phid' => 'PHID-PROJ-1',
                    'fields' => [
                        'name' => 'Employees',
                    ],
                ],
                [
                    'id' => 42,
                    'type' => 'PROJ',
                    'phid' => 'PHID-PROJ-2',
                    'fields' => [
                        'name' => 'Collaborators',
                    ],
                ]
            ],
            'cursor' => [
                'after' => null,
            ]
        ];
        $this->conduit
        ->shouldReceive('callMethodSynchronous')
        ->with('project.search', [
            'constraints' => [
                'icons' => [
                    'group',
                ],
            ],
        ])
        ->once()
        ->andReturn($groups);
        $this->lookupGroupProjects()->shouldReturn($groups);
    }

    function it_retrieves_redmine_project_members(Client $redmine, Membership $membership)
    {
        $redmine->api('membership')->willReturn($membership);
        $membership->all('23')->willReturn([
            'memberships'  => [
                [
                    'id' => 187,
                    'project' => [
                        'id' => 23,
                        'name' => 'Some project',
                    ],
                    'user' => [
                        'id' => 11,
                        'name' => 'Lisa Lotte',
                    ],
                    'roles' => [
                        [
                            'id' => 4,
                            'name' => 'Developer',
                            'inherited' => 1,
                        ],
                    ],
                ],
                [
                    'id' => 188,
                    'project' => [
                        'id' => 23,
                        'name' => 'Some project',
                    ],
                    'user' => [
                        'id' => 12,
                        'name' => 'Stan Stocks',
                    ],
                    'roles' => [
                        [
                            'id' => 4,
                            'name' => 'Developer',
                            'inherited' => 1,
                        ],
                    ],
                ]
            ]
        ]);
        $this->getRedmineProjectMembers(23)->shouldReturn([
            'Lisa Lotte',
            'Stan Stocks',
        ]);
    }

    function it_creates_a_new_phabricator_project_from_redmine_details()
    {
        $detail = [
            'project' => [
                'id' => 59,
                'name' => 'Test Project',
                'description' => 'Project description',
                'status' => 1,
            ]
        ];
        $phab_members = [
            'PHID-USER-dj4xmfuwa6z4ycm6d764',
            'PHID-USER-jfllws6pouiwhzflb3p7',
        ];
        $policies = [
            'view' => 'PHID-PROJ-5lryozud2u4wog3ah7lt',
            'edit' => 'PHID-PROJ-5lryozud2u4wog3ah7lt',
        ];
        $project_edited = [
            'object' => [
                'id' => 64,
                'phid' => 'PHID-PROJ-jtvatlu3ptn6lepfqjr6',
            ],
            'transactions' => [
                [
                    'phid' => 'PHID-XACT-PROJ-fgbfirms6x7e5kn',
                ],
                [
                    'phid' => 'PHID-XACT-PROJ-mmn5wo6efgyxaf6',
                ],
                [
                    'phid' => 'PHID-XACT-PROJ-dafrj6yrkdjojdj',
                ],
                [
                    'phid' => 'PHID-XACT-PROJ-ls7kyu3v77ahi4t',
                ],
                [
                    'phid' => 'PHID-XACT-PROJ-3mpflqb4isvr7gh',
                ],
                [
                    'phid' => 'PHID-XACT-PROJ-wjzzunrkrtaao6s',
                ],
            ]
        ];

        $this->conduit
        ->shouldReceive('callMethodSynchronous')
        ->with('project.edit', [
            'objectIdentifier' => null,
            'transactions' => [
                [
                    'type' => 'name',
                    'value' => 'Test Project',
                ],
                [
                    'type' => 'members.add',
                    'value' => [
                        'PHID-USER-dj4xmfuwa6z4ycm6d764',
                        'PHID-USER-jfllws6pouiwhzflb3p7',
                    ]
                ],
                [
                    'type' => 'view',
                    'value' => 'PHID-PROJ-5lryozud2u4wog3ah7lt'
                ],
                [
                    'type' => 'edit',
                    'value' => 'PHID-PROJ-5lryozud2u4wog3ah7lt'
                ],
                [
                    'type' => 'join',
                    'value' => 'PHID-PROJ-5lryozud2u4wog3ah7lt'
                ],
            ],
        ])
        ->andReturn($project_edited);

        $this->createNewPhabricatorProject(
            $detail,
            $phab_members,
            $policies
        )->shouldReturn($project_edited);
    }

    function it_validates_user_input_for_selections()
    {
        $mock = PHPMockery::mock('\Ttf\Remaim', 'fgets')->andReturn('3');
        ob_start();
        $this->selectIndexFromList('Select something', 4)->shouldReturn('3');
        $out = ob_get_clean();
        expect($out)->toBe('Select something:' . PHP_EOL.'> ');
    }

    function it_accepts_empty_input_for_selections_if_requested()
    {
        $mock = PHPMockery::mock('\Ttf\Remaim', 'fgets')->andReturn('');
        ob_start();
        $this->selectIndexFromList('Select something', 4, 0, true)->shouldReturn('');
        $out = ob_get_clean();
        expect($out)->toBe('Select something:' . PHP_EOL.'> ');
    }

    function it_recurses_if_the_select_value_does_not_validate()
    {
        $mock = PHPMockery::mock('\Ttf\Remaim', 'fgets')->andReturn('4', '2');
        ob_start();
        $this->selectIndexFromList('Select something', 2)->shouldReturn('2');
        $out = ob_get_clean();
        expect($out)->toBe('Select something:' . PHP_EOL.'> You must select a value between 0 and 2' . PHP_EOL . 'Select something:' . PHP_EOL . '> ');
    }

    /**
     * This should rather be a functional test than a unit test though.
     */
    function it_prints_a_message_if_the_given_project_id_does_not_exist()
    {
        $mock = PHPMockery::mock('\Ttf\Remaim', 'fgets')->andReturn('3', 'foobar');
        $this->conduit
        ->shouldReceive('callMethodSynchronous')
        ->with('project.search', [
            'queryKey' => 'all',
            'after' => 0,
        ])
        ->once()
        ->andReturn([
            'data' => [
                [
                    'id' => 1,
                    'phid' => 'PHID-project-1',
                    'fields' => [
                        'name' => 'First project',
                    ]
                ],
                [
                    'id' => 4,
                    'phid' => 'PHID-project-2',
                    'fields' => [
                        'name' => 'Second project',
                    ]
                ],
            ],
            'cursor' => [
                'after' => null,
            ],
        ]);
        $this->conduit
        ->shouldReceive('callMethodSynchronous')
        ->with('project.query', [
            'slugs' => ['foobar']
        ])
        ->once()
        ->andReturn([]);

        ob_start();
        $this->actOnChoice('', 1);
        $print = ob_get_clean();

        expect($print)->toBe(
            "2 total projects retrieved.\n\n[1 – First project]\n[4 – Second project]\n\nPlease select (type) a project ID or leave empty to go back to the previous step:\n> Sorry, if a project with id 3 exists, you don't seem to have access to it. Please check your permissions and the id you specified and try again.\nPlease enter the id or slug of the project in Phabricator if you know it.\nPress\n[Enter] to see a list of available projects in Phabricator,\n[0] to create a new project from the Redmine project's details or\n[q] to quit and abort:\n> "
        );
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

        $this->shouldThrow('\Ttf\Remaim\Exception\NoIssuesFoundException')->during('getIssuesForProject', [1]);
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
        $this->shouldThrow('\Ttf\Remaim\Exception\NoIssuesFoundException')->during('getIssuesForProject', [1]);
    }

    function it_returns_issue_details_if_issues_are_found(Client $redmine, Issue $issue)
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

    function it_looks_up_assignee_phids_from_phabricator()
    {
        $issue = [
            'assigned_to' => [
                'name' => 'Albert Einstein',
            ]
        ];
        $this->conduit
        ->shouldReceive('callMethodSynchronous')
        ->with('user.query', ['realnames' => ['Albert Einstein']])
        ->once()
        ->andReturn([
            [
                'phid' => 'PHID-user-albert',
                'realName' => 'Albert Einstein',
            ]
        ]);
        $this->grabOwnerPhid($issue)->shouldReturn('PHID-user-albert');
    }

    function it_returns_an_empty_array_if_no_existing_user_was_found()
    {
        $lookupone = [
            'James',
            'Alfred',
        ];
        $this->conduit
        ->shouldReceive('callMethodSynchronous')
        ->with('user.query', [
            'realnames' => $lookupone
        ])
        ->once()
        ->andReturn([]);

        $this->getPhabricatorUserPhid($lookupone)->shouldReturn([]);
    }

    function it_accepts_indexes_unknown_stati()
    {
        $mock = PHPMockery::mock('\Ttf\Remaim', 'fgets')->andReturn('0');
        $issue = [
            'subject' => 'Test Subject',
            'attachments' => [],
            'status' => [
                'id' => 1,
                'name' => 'Unknown',
            ],
            'description' => 'A random description of a task',
        ];

        ob_start();
        $this->createStatusTransaction($issue)->shouldReturn([
            'type' => 'status',
            'value' => 'open'
        ]);
        $output = ob_get_clean();
        expect($output)->toBe(
            'We could not find a matching key for the status "Unknown"!' . PHP_EOL
            . '[0] – open' . PHP_EOL
            . '[1] – resolved' . PHP_EOL
            . 'Select a status to use:' . PHP_EOL
            . '> '
        );
    }

    function it_also_accepts_values_for_unknown_stati()
    {
        $mock = PHPMockery::mock('\Ttf\Remaim', 'fgets')->andReturn('open');
        $issue = [
            'subject' => 'Test Subject 2',
            'attachments' => [],
            'status' => [
                'id' => 2,
                'name' => 'Unknown',
            ],
            'description' => 'A random description of another task',
        ];
        ob_start();
        $this->createStatusTransaction($issue)->shouldReturn([
            'type' => 'status',
            'value' => 'open'
        ]);
        $output = ob_get_clean();
        expect($output)->toBe(
            'We could not find a matching key for the status "Unknown"!' . PHP_EOL
            . '[0] – open' . PHP_EOL
            . '[1] – resolved' . PHP_EOL
            . 'Select a status to use:' . PHP_EOL
            . '> '
        );
    }

    function it_keeps_asking_if_the_status_cannot_be_used()
    {
        $mock = PHPMockery::mock('\Ttf\Remaim', 'fgets')->andReturn('2', 'open');
        $issue = [
            'subject' => 'Test Subject',
            'attachments' => [],
            'status' => [
                'id' => 6,
                'name' => 'Unknown',
            ],
            'description' => 'A random description of a task',
        ];
        ob_start();
        $this->createStatusTransaction($issue)->shouldReturn([
            'type' => 'status',
            'value' => 'open'
        ]);
        $output = ob_get_clean();
        expect($output)->toBe('We could not find a matching key for the status "Unknown"!' . PHP_EOL
            . '[0] – open' . PHP_EOL
            . '[1] – resolved' . PHP_EOL
            . 'Select a status to use:' . PHP_EOL
            . '> '
            . 'We could not find a matching key for the status "Unknown"!' . PHP_EOL
            . '[0] – open' . PHP_EOL
            . '[1] – resolved' . PHP_EOL
            . 'Select a status to use:' . PHP_EOL
            . '> '
        );
    }

    function it_does_not_ask_twice_for_the_same_unknown_status()
    {
        $mock = PHPMockery::mock('\Ttf\Remaim', 'fgets')->andReturn('0');
        $issue = [
            'subject' => 'Test Subject',
            'attachments' => [],
            'status' => [
                'id' => 6,
                'name' => 'Unknown',
            ],
            'description' => 'A random description of a task',
        ];
        ob_start();
        $this->createStatusTransaction($issue)->shouldReturn([
            'type' => 'status',
            'value' => 'open'
        ]);
        $output = ob_get_clean();
        expect($output)->toBe('We could not find a matching key for the status "Unknown"!' . PHP_EOL
            . '[0] – open' . PHP_EOL
            . '[1] – resolved' . PHP_EOL
            . 'Select a status to use:' . PHP_EOL
            . '> '
        );
        $this->createStatusTransaction($issue)->shouldReturn([
            'type' => 'status',
            'value' => 'open'
        ]);
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
                ],
                'description' => 'A random description of a task',
            ]
        ];
        $policies = [
            'view' => 'PHID-foobar',
            'edit' => 'PHID-barbaz',
        ];

        $expectedTransactions = [
            [
                'type' => 'projects.set',
                'value' => ['PHID-random'],
            ],
            [
                'type' => 'title',
                'value' => 'Test Subject',
            ],
            [
                'type' => 'description',
                'value' => 'A random description of a task',
            ],
            [
                'type' => 'status',
                'value' => 'resolved',
            ],
            [
                'type' => 'view',
                'value' => 'PHID-foobar',
            ],
            [
                'type' => 'edit',
                'value' => 'PHID-barbaz',
            ]
        ];

        $this->assembleTransactionsFor(
            'PHID-random',
            $details['issue'],
            $policies
        )->shouldReturn($expectedTransactions);
    }

    function it_generates_a_title_transaction_if_the_title_has_changed()
    {
        $details = [
            'issue' => [
                'subject' => 'A changed Subject',
                'attachments' => [],
                'status' => [
                    'id' => 1,
                    'name' => 'Resolved',
                ],
                'description' => 'A random description of a task',
            ]
        ];
        $policies = [
            'view' => 'PHID-foobar',
            'edit' => 'PHID-barbaz',
        ];

        $task = [
            'title' => 'Test Subject',
            'description' => '',
        ];

        $expectedTransactions = [
            [
                'type' => 'projects.set',
                'value' => ['PHID-random'],
            ],
            [
                'type' => 'title',
                'value' => 'A changed Subject',
            ],
            [
                'type' => 'description',
                'value' => 'A random description of a task',
            ],
            [
                'type' => 'status',
                'value' => 'resolved',
            ],
            [
                'type' => 'view',
                'value' => 'PHID-foobar',
            ],
            [
                'type' => 'edit',
                'value' => 'PHID-barbaz',
            ]
        ];

        $this->assembleTransactionsFor(
            'PHID-random',
            $details['issue'],
            $policies,
            $task
        )->shouldReturn($expectedTransactions);
    }

    function it_does_not_generate_a_transaction_if_the_title_has_not_changed()
    {
        $details = [
            'issue' => [
                'subject' => 'Test Subject',
                'attachments' => [],
                'status' => [
                    'id' => 1,
                    'name' => 'Resolved',
                ],
                'description' => 'A random description of a task',
            ]
        ];
        $policies = [
            'view' => 'PHID-foobar',
            'edit' => 'PHID-barbaz',
        ];

        $task = [
            'title' => 'Test Subject',
            'description' => '',
        ];

        $expectedTransactions = [
            [
                'type' => 'projects.set',
                'value' => ['PHID-random'],
            ],
            [
                'type' => 'description',
                'value' => 'A random description of a task',
            ],
            [
                'type' => 'status',
                'value' => 'resolved',
            ],
            [
                'type' => 'view',
                'value' => 'PHID-foobar',
            ],
            [
                'type' => 'edit',
                'value' => 'PHID-barbaz',
            ]
        ];

        $this->assembleTransactionsFor(
            'PHID-random',
            $details['issue'],
            $policies,
            $task
        )->shouldReturn($expectedTransactions);
    }

    function it_presents_a_summary_before_initiating_the_migration(Client $redmine, Project $project)
    {
        $project_detail = [
            'project' => [
                'name' => 'Redmine Project',
            ],
        ];

        $redmine->api('project')->willReturn($project);
        $project->show(34)->willReturn($project_detail);
        $mock = PHPMockery::mock('\Ttf\Remaim', 'fgets')->andReturn('y');

        $phabricator_project = [
            'name' => 'Phabricator Project',
            'id' => 78,
        ];
        $policies = [
            'view' => 'PHID-foobar',
            'edit' => 'PHID-barbaz',
        ];
        $tasks = [
            'total_count' => [10],
        ];
        ob_start();
        $this->presentSummary(
            34,
            $phabricator_project,
            $tasks,
            $policies
        )->shouldReturn(true);
        $output = ob_get_clean();
        expect($output)->toBe(PHP_EOL . PHP_EOL .
            '####################' . PHP_EOL .
            '# Pre-flight check #' . PHP_EOL .
            '####################' . PHP_EOL .
            'Redmine project named "Redmine Project" with ID 34.' . PHP_EOL .
            'Target phabricator project named "Phabricator Project" with ID 78.' . PHP_EOL .
            'View policy: PHID-foobar, Edit policy: PHID-barbaz' . PHP_EOL .
            '10 tickets to be migrated!' . PHP_EOL . PHP_EOL . 'OK to continue? [y/N]:' . PHP_EOL .
            '> '
        );
    }

    function it_forces_a_specific_protocol_if_it_has_been_set_in_the_config()
    {
        $mock = PHPMockery::mock('\Ttf\Remaim\Traits', 'file_get_contents')->andReturn('blablabla');
        $details = [
            'issue' => [
                'subject' => 'Test Subject',
                'attachments' => [
                    [
                        'filename' => 'Testfile.png',
                        'content_url' => 'http://redmine.host/files/Testfile.png',
                    ]
                ],
                'status' => [
                    'id' => 1,
                    'name' => 'Resolved',
                ],
                'description' => 'A random description of a task',
            ]
        ];

        $this->conduit
        ->shouldReceive('callMethodSynchronous')
        ->with('file.upload', [
                'name' => 'Testfile.png',
                'data_base64' => base64_encode('blablabla'),
                'viewPolicy' => 'PHID-foobar',
        ])
        ->once()
        ->andReturn('PHID-file-xyz');

        $this->conduit
        ->shouldReceive('callMethodSynchronous')
        ->with('file.info', [
                'phid' => 'PHID-file-xyz',
            ])
        ->once()
        ->andReturn([
            'objectName' => 'F123456'
        ]);

        $this->uploadFiles(
            $details['issue'],
            'PHID-foobar'
        )->shouldReturn([
            '{F123456}'
        ]);
    }

    function it_attaches_uploaded_files_to_the_task_description()
    {
        $mock = PHPMockery::mock('\Ttf\Remaim\Traits', 'file_get_contents')->andReturn('blablabla');
        $details = [
            'issue' => [
                'subject' => 'Test Subject',
                'attachments' => [
                    [
                        'filename' => 'Testfile.png',
                        'content_url' => 'https://redmine.host/files/Testfile.png',
                    ]
                ],
                'status' => [
                    'id' => 1,
                    'name' => 'Resolved',
                ],
                'description' => 'A random description of a task',
            ]
        ];
        $policies = [
            'view' => 'PHID-foobar',
            'edit' => 'PHID-barbaz',
        ];

        $this->conduit
        ->shouldReceive('callMethodSynchronous')
        ->with('file.upload', [
                'name' => 'Testfile.png',
                'data_base64' => base64_encode('blablabla'),
                'viewPolicy' => $policies['view'],
        ])
        ->once()
        ->andReturn('PHID-file-xyz');

        $this->conduit
        ->shouldReceive('callMethodSynchronous')
        ->with('file.info', [
                'phid' => 'PHID-file-xyz',
            ])
        ->once()
        ->andReturn([
            'objectName' => 'F123456'
        ]);

        $expectedTransactions = [
            [
                'type' => 'projects.set',
                'value' => ['PHID-random'],
            ],
            [
                'type' => 'title',
                'value' => 'Test Subject',
            ],
            [
                'type' => 'description',
                'value' => "A random description of a task\n\n{F123456}",
            ],
            [
                'type' => 'status',
                'value' => 'resolved',
            ],
            [
                'type' => 'view',
                'value' => 'PHID-foobar',
            ],
            [
                'type' => 'edit',
                'value' => 'PHID-barbaz',
            ]
        ];

        $this->assembleTransactionsFor(
            'PHID-random',
            $details['issue'],
            $policies,
            []
        )->shouldReturn($expectedTransactions);
    }

    function it_handles_redmine_journals_and_transforms_them_into_comments()
    {
        $issue = [
            'subject' => 'Test Subject',
            'attachments' => [],
            'status' => [
                'id' => 1,
                'name' => 'Resolved',
            ],
            'description' => 'A random description of a task',
            'journals' => [
                [
                    'id' => 6535,
                    'user' => [
                        'id' => 24,
                        'name' => 'Albert Einstein',
                    ],
                    'notes' => 'A comment _someone_ made with @code@',
                    'created_on' => '2015-04-27T15:55:47Z',
                    'details' => [
                        [
                            'property' => 'attr',
                            'name' => 'done_ratio',
                            'old_value' => 90,
                            'new_value' => 100
                        ]
                    ]
                ]
            ]
        ];
        $policies = [
            'view' => 'PHID-foobar',
            'edit' => 'PHID-barbaz',
        ];

        $expectedTransactions = [
            [
                'type' => 'projects.set',
                'value' => ['PHID-random'],
            ],
            [
                'type' => 'title',
                'value' => 'Test Subject',
            ],
            [
                'type' => 'description',
                'value' => "A random description of a task",
            ],
            [
                'type' => 'status',
                'value' => 'resolved',
            ],
            [
                'type' => 'comment',
                'value' => "On Monday, April 27th 2015 15:55:47, Albert Einstein wrote:\n > A comment //someone// made with `code`\nand:\n - changed done from 90% to 100%"
            ],
            [
                'type' => 'view',
                'value' => 'PHID-foobar',
            ],
            [
                'type' => 'edit',
                'value' => 'PHID-barbaz',
            ],
        ];

        $this->assembleTransactionsFor(
            'PHID-random',
            $issue,
            $policies,
            []
        )->shouldReturn($expectedTransactions);
    }

    function it_handles_redmine_journals_and_transforms_details_into_comment_addons()
    {
        $issue = [
            'subject' => 'Test Subject',
            'attachments' => [],
            'status' => [
                'id' => 1,
                'name' => 'Resolved',
            ],
            'description' => 'A random description of a task',
            'journals' => [
                [
                    'id' => 6535,
                    'user' => [
                        'id' => 24,
                        'name' => 'Albert Einstein',
                    ],
                    'notes' => 'A comment someone made',
                    'created_on' => '2015-04-27T15:55:47Z',
                    'details' => [
                        [
                            'property' => 'cf',
                            'name' => '1',
                            'old_value' => 'old',
                            'new_value' => 'new'
                        ]
                    ]
                ]
            ]
        ];
        $policies = [
            'view' => 'PHID-foobar',
            'edit' => 'PHID-barbaz',
        ];

        $expectedTransactions = [
            [
                'type' => 'projects.set',
                'value' => ['PHID-random'],
            ],
            [
                'type' => 'title',
                'value' => 'Test Subject',
            ],
            [
                'type' => 'description',
                'value' => "A random description of a task",
            ],
            [
                'type' => 'status',
                'value' => 'resolved',
            ],
            [
                'type' => 'comment',
                'value' => "On Monday, April 27th 2015 15:55:47, Albert Einstein wrote:\n > A comment someone made\nand:\n - changed \"Billed\" from \"old\" to \"new\""
            ],
            [
                'type' => 'view',
                'value' => 'PHID-foobar',
            ],
            [
                'type' => 'edit',
                'value' => 'PHID-barbaz',
            ],
        ];

        $this->assembleTransactionsFor(
            'PHID-random',
            $issue,
            $policies,
            []
        )->shouldReturn($expectedTransactions);
    }

    function it_saves_unknown_redmine_journals_entries_into_serialed_data()
    {
        $issue = [
            'subject' => 'Test Subject',
            'attachments' => [],
            'status' => [
                'id' => 1,
                'name' => 'Resolved',
            ],
            'description' => 'A random description of a task',
            'journals' => [
                [
                    'id' => 6535,
                    'user' => [
                        'id' => 24,
                        'name' => 'Albert Einstein',
                    ],
                    'notes' => 'A comment someone made',
                    'created_on' => '2015-04-27T15:55:47Z',
                    'details' => [
                        [
                            'property' => 'attr',
                            'name' => 'unknown',
                            'unknown_property' => true
                        ]
                    ]
                ]
            ]
        ];
        $policies = [
            'view' => 'PHID-foobar',
            'edit' => 'PHID-barbaz',
        ];

        $expectedTransactions = [
            [
                'type' => 'projects.set',
                'value' => ['PHID-random'],
            ],
            [
                'type' => 'title',
                'value' => 'Test Subject',
            ],
            [
                'type' => 'description',
                'value' => "A random description of a task",
            ],
            [
                'type' => 'status',
                'value' => 'resolved',
            ],
            [
                'type' => 'comment',
                'value' => "On Monday, April 27th 2015 15:55:47, Albert Einstein wrote:\n > A comment someone made\nand:\n - changed another property I don't know about: a:3:{s:8:\"property\";s:4:\"attr\";s:4:\"name\";s:7:\"unknown\";s:16:\"unknown_property\";b:1;}"
            ],
            [
                'type' => 'view',
                'value' => 'PHID-foobar',
            ],
            [
                'type' => 'edit',
                'value' => 'PHID-barbaz',
            ],
        ];
        ob_start();
        $this->assembleTransactionsFor(
            'PHID-random',
            $issue,
            $policies,
            []
        )->shouldReturn($expectedTransactions);
        ob_end_clean();
    }

    function it_transforms_watchers_into_subscribers()
    {
        $issue = [
            'subject' => 'Test Subject',
            'attachments' => [],
            'status' => [
                'id' => 1,
                'name' => 'Resolved',
            ],
            'description' => 'A random description of a task',
            'watchers' => [
                [
                    'id' => 1,
                    'name' => 'Tom Sawyer',
                ],
                [
                    'id' => 5,
                    'name' => 'Miles Davis',
                ]
            ]
        ];

        $policies = [
            'view' => 'PHID-foobar',
            'edit' => 'PHID-barbaz',
        ];

        $expectedTransactions = [
            [
                'type' => 'projects.set',
                'value' => ['PHID-random'],
            ],
            [
                'type' => 'title',
                'value' => 'Test Subject',
            ],
            [
                'type' => 'description',
                'value' => "A random description of a task",
            ],
            [
                'type' => 'status',
                'value' => 'resolved',
            ],
            [
                'type' => 'subscribers.set',
                'value' => [
                    'PHID-tom',
                    'PHID-miles',
                ],
            ],
            [
                'type' => 'view',
                'value' => 'PHID-foobar',
            ],
            [
                'type' => 'edit',
                'value' => 'PHID-barbaz',
            ],
        ];

        $this->conduit
        ->shouldReceive('callMethodSynchronous')
        ->with('user.query', [
            'realnames' => [
                'Tom Sawyer',
                'Miles Davis',
            ],
        ])
        ->once()
        ->andReturn([
            [
                'realName' => 'Tom Sawyer',
                'phid' => 'PHID-tom',
            ],
            [
                'realName' => 'Miles Davis',
                'phid' => 'PHID-miles',
            ]
        ]);

        $this->assembleTransactionsFor(
            'PHID-random',
            $issue,
            $policies,
            []
        )->shouldReturn($expectedTransactions);
    }

    function it_creates_a_new_task_if_no_match_is_found_in_phabricator()
    {
        $issue = [
            'subject' => 'Test Subject',
            'attachments' => [],
            'status' => [
                'id' => 1,
                'name' => 'Resolved',
            ],
            'description' => 'A random description of a task',
        ];
        $policies = [
            'view' => 'PHID-foobar',
            'edit' => 'PHID-barbaz',
        ];

        $result = [
            'title' => 'Test Subject',
            'description' => 'A random description of a task',
            'ownerPHID' => 'PHID-owner',
            'priority' => 100,
            'projectPHIDs' => [
                'PHID-project'
            ],
        ];

        $owner = [
            'realName' => 'Johnny 5',
            'phid' => 'PHID-owner',
        ];

        $this->conduit
        ->shouldReceive('callMethodSynchronous')
        ->with('maniphest.query', [
            'projectPHIDs' => ['PHID-project'],
            'fullText' => 'A random description of a task'
        ])
        ->once()
        ->andReturn([]);

        $this->conduit
        ->shouldReceive('callMethodSynchronous')
        ->with(
            'maniphest.edit',
            \Mockery::type('array')
        )
        ->once()
        ->andReturn($result);

        $this->createManiphestTask(
            $issue,
            $owner,
            'PHID-project',
            $policies
        )->shouldReturn($result);
    }

    function it_asks_which_task_to_update_if_more_than_one_existing_task_is_found()
    {
        $mock = PHPMockery::mock('\Ttf\Remaim', 'fgets')->andReturn('0');
        $issue = [
            'subject' => 'Test Subject',
            'attachments' => [],
            'status' => [
                'id' => 1,
                'name' => 'Resolved',
            ],
            'description' => 'A random description of a task',
        ];
        $this->conduit
        ->shouldReceive('callMethodSynchronous')
        ->with('maniphest.query', [
            'projectPHIDs' => ['PHID-project'],
            'fullText' => 'A random description of a task'
        ])
        ->once()
        ->andReturn([
            [
                'id' => 1,
                'statusName' => 'Resolved',
                'title' => 'Test Subject',
                'description' => 'A random description of a task',
            ],
            [
                'id' => 2,
                'statusName' => 'Open',
                'title' => 'Similar Task Subject',
                'description' => 'A random description of a task',
            ],
        ]);

        ob_start();
        $this->findExistingTask($issue, 'PHID-project')->shouldReturn([
            'id' => 1,
            'statusName' => 'Resolved',
            'title' => 'Test Subject',
            'description' => 'A random description of a task',
        ]);
        $prompt = ob_get_clean();
        expect($prompt)->toBe(
            "Oops, I found more than one already existing task in phabricator.\nPlease indicate which one to update.\n[0] =>\t[ID]: T1\n\t[Status]: Resolved\n\t[Name]: Test Subject\n\t[Description]: A random description of a task\n[1] =>\t[ID]: T2\n\t[Status]: Open\n\t[Name]: Similar Task Subject\n\t[Description]: A random description of a task\nEnter the [index] of the task you would like to use:\n> "
        );
    }

    function it_updates_the_task_if_only_one_is_found_in_phabricator()
    {
        $issue = [
            'subject' => 'Test Subject',
            'attachments' => [],
            'status' => [
                'id' => 1,
                'name' => 'Resolved',
            ],
            'description' => 'A random description of a task',
        ];
        $this->conduit
        ->shouldReceive('callMethodSynchronous')
        ->with('maniphest.query', [
            'projectPHIDs' => ['PHID-project'],
            'fullText' => 'A random description of a task'
        ])
        ->once()
        ->andReturn([
            [
                'id' => 1,
                'statusName' => 'Resolved',
                'title' => 'Test Subject',
                'description' => 'A random description of a task',
            ]
        ]);
        $this->findExistingTask($issue, 'PHID-project')->shouldReturn([
            'id' => 1,
            'statusName' => 'Resolved',
            'title' => 'Test Subject',
            'description' => 'A random description of a task',
        ]);
    }

    function it_paginates_trough_all_the_results_if_there_are_more_than_100()
    {
        $this->conduit
        ->shouldReceive('callMethodSynchronous')
        ->with(
            'project.search',
            [
                'queryKey' => 'all',
                'after' => 0,
            ]
        )
        ->once()
        ->andReturn([
            'data' => ['a', 'b'],
            'cursor' => [
                'after' => 23
            ]
        ]);

        $this->conduit
        ->shouldReceive('callMethodSynchronous')
        ->with(
            'project.search',
            [
                'queryKey' => 'all',
                'after' => 23,
            ]
        )
        ->once()
        ->andReturn([
            'data' => ['c', 'd'],
            'cursor' => [
                'after' => null
            ]
        ]);

        $this->retrieveAllPhabricatorProjects(0)->shouldReturn([
            'a', 'b', 'c', 'd'
        ]);
    }
}
