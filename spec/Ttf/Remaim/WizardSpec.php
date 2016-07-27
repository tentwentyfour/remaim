<?php
/**
 * PhpSpec file for Remaim
 */

namespace spec\Ttf\Remaim;

use PhpSpec\ObjectBehavior;
use Prophecy\Argument;
use Mockery as m;

use Ttf\Remaim\Wizard;  // Class under test

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
        $this->shouldThrow('\InvalidArgumentException')->duringTestConnectionToRedmine();
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
        $this->shouldThrow('\InvalidArgumentException')->duringTestConnectionToRedmine();
    }

    function it_throws_an_exception_if_redmine_returns_an_non_array_value(Client $redmine, Project $project)
    {
        $redmine->api('project')->willReturn($project);
        $project->listing()->shouldBeCalled();
        $project->listing()->willReturn(1);
        $this->shouldThrow('\InvalidArgumentException')->duringTestConnectionToRedmine();
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

    function it_should_return_a_structured_list_of_projects(Client $redmine, Project $project)
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

    function it_should_return_the_projects_id_and_name()
    {
        $project = [
            'id' => 5,
            'name' => 'Tests'
        ];
        $this->representProject($project)->shouldReturn("[5 – Tests]\n");
    }

    function it_should_throw_an_exception_if_no_tasks_are_found(Client $redmine, Issue $issue)
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

    function it_should_throw_an_exception_if_looking_up_tasks_failed(Client $redmine, Issue $issue)
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

    function it_should_return_true_if_a_task_is_found(Client $redmine, Issue $issue)
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
        $lookup = [];
        $query_result = [];
          $this->conduit
        ->shouldReceive('callMethodSynchronous')
        ->with('user.query', $lookup)
        ->times(1)
        ->andReturn($query_result);

        $this->getPhabricatorUserPhid()->shouldReturn();
    }

}
