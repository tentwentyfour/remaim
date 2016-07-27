<?php

namespace spec\Ttf\Remaim;

use Ttf\Remaim\Wizard;
use PhpSpec\ObjectBehavior;
use Prophecy\Argument;
use Redmine\Client;
use Redmine\Api\Project;

// require_once '/usr/share/libphutil/src/__phutil_library_init__.php';

class WizardSpec extends ObjectBehavior
{

    /**
     * @todo  Find a way to mock ConduitClient which is marked as final
     * PhpSpec/Prophecy sucks… maybe Mockery is better?
     * See http://docs.mockery.io/en/latest/
     *
     * @return void
     */
    public function let(Client $redmine, Project $project, \stdClass $conduit)
    {
        $config = [
            'redmine' => [
            ],
            'phabricator' => [
            ],
        ];
        // $conduit = new \stdClass();
        $this->beConstructedWith($config, $redmine, $conduit);
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

    function xit_should_be_able_to_look_up_a_phabricator_project_by_its_id(\stdClass $conduit)
    {
        $project_array = [
            'phid' => 'test-phid',
            'name' => 'test-project-name',
        ];
        $conduit->callMethodSynchronous()->willReturn($project_array);
        $this->findPhabProjectWithIdSlug()->shouldReturn($project_array);
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
