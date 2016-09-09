<?php

namespace spec\Ttf\Remaim\Facade;

use phpmock\mockery\PHPMockery;

use Redmine\Client;
use Redmine\Api\Issue;
use Redmine\Api\Project;
use Redmine\Api\Membership;
use Ttf\Remaim\Facade\Redmine;
use PhpSpec\ObjectBehavior;
use Prophecy\Argument;

class RedmineSpec extends ObjectBehavior
{
    public function let(Client $redmine)
    {
        $this->beConstructedWith($redmine);
    }

    function it_is_initializable()
    {
        $this->shouldHaveType(Redmine::class);
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

        $this->listProjects()->shouldReturn($project_array);
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
        $this->getProjectMembers(23)->shouldReturn([
            'Lisa Lotte',
            'Stan Stocks',
        ]);
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


}
