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
            'A random description of a task',
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
            'A random description of a task',
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
            'A random description of a task',
            $policies,
            $task
        )->shouldReturn($expectedTransactions);
    }

    /**
     * I don't understand why this test makes the maniphest.querystatuses expectation
     * from the constructor fail, even if it contains but a single print statement.
     */
    function xit_attaches_uploaded_files_to_the_task_description()
    {
        $mock = PHPMockery::mock(__NAMESPACE__, 'file_get_contents')->andReturn('blablabla');
        $details = [
            'issue' => [
                'subject' => 'Test Subject',
                'attachments' => [
                    [
                        'filename' => 'Testfile.png',
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
        ->andReturn('F123456');

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
            'A random description of a task',
            $policies,
            $task
        )->shouldReturn($expectedTransactions);
    }

    /**
     * Same problem as above
     */
    function xit_creates_a_new_task_if_no_match_is_found_in_phabricator()
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
        $description = $issue['description'];
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

        $this->conduit
        ->shouldReceive('callMethodSynchronous')
        ->with(
            'maniphest.edit',
            \Mockery::type('array')
        )
        ->once()
        ->andReturn($result);

        $this->createManiphestTask(
            [],
            $details,
            $description,
            'PHID-owner',
            'PHID-project',
            $policies
        )->shouldReturn($result);
    }
}
