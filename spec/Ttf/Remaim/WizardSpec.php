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
     * @param
     * @return [type]
     */
    public function let(Client $redmine, Project $project)
    {
        $config = [
            'redmine' => [
            ],
            'phabricator' => [
            ],
        ];
        $conduit = new \stdClass();
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
