<?php

namespace spec\Ttf\Remaim;

use Ttf\Remaim\Wizard;
use PhpSpec\ObjectBehavior;
use Prophecy\Argument;
use Redmine\Client;

// require_once '/usr/share/libphutil/src/__phutil_library_init__.php';

class WizardSpec extends ObjectBehavior
{

    /**
     * @todo  Find a way to mock ConduitClient which is marked as final
     * @param  
     * @return [type]
     */
    public function let(Client $redmine)
    {
        $config = [
            'redmine' => [
            ],
            'phabricator' => [
            ],
        ];
        $conduit = [];
        $this->beConstructedWith($config, $redmine, $conduit);
    }

    function it_is_initializable()
    {
        $this->shouldHaveType(Wizard::class);
    }

    function it_returns_foo_on_run()
    {
        $this->run()->shouldReturn('foo');
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
