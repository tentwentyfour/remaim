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

    function it_should_return_true_if_it_can_connect_to_redmine(Client $redmine, Project $project)
    {
        $redmine->api('project')->willReturn($project);
        $project->listing()->shouldBeCalled();
        $project->listing()->willReturn(' ');
        $this->testConnectionToRedmine()->shouldReturn(true);
    }

    function it_should_return_false_if_it_cannot_connect_to_redmine(Client $redmine, Project $project)
    {
        $redmine->api('project')->willReturn($project);
        $project->listing()->shouldBeCalled();
        $project->listing()->willReturn();
        $this->shouldThrow('\InvalidArgumentException')->duringTestConnectionToRedmine();
    }

    function it_should_be_able_to_look_up_a_phabricator_project_by_its_id()
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

    function it_should_return_a_list_of_projects(Client $redmine, Project $project)
    {
        $redmine->api('issue')->willReturn($project);
        $project->all()->shouldBeCalled();
    }

    function it_should_return_true_if_a_task_is_found(Client $redmine, Project $tasks)
    {
        $redmine->api('issue')->willReturn($tasks);
        $tasks->all()->shouldBeCalled();
        $tasks->all()->willReturn(' ');
        $this->listIssuesAndProjectdetails()->shouldReturn(true);
    }




    // function it_shows_a_list_of_the_projects(Wizard $project_create)
    // {
    //     $this->representProject($project)->shouldReturn('')
    // }

    /*     function it_converts_text_from_an_external_source($reader)
    {
        $reader->beADoubleOf('Markdown\Reader');
        $reader->getMarkdown()->willReturn("Hi, there");

        $this->toHtmlFromReader($reader)->shouldReturn("<p>Hi, there</p>");
    } */

}
