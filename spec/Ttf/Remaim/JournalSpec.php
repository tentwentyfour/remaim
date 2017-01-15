<?php

namespace spec\Ttf\Remaim;

use Ttf\Remaim\Journal;
use Ttf\Remaim\Facade\Redmine as Facade;

use PhpSpec\ObjectBehavior;
use Prophecy\Argument;

use Mockery as m;

use Pimple\Container;

class JournalSpec extends ObjectBehavior
{
    public function let()
    {
        date_default_timezone_set('UTC');
        $this->beConstructedWith(new Container);
    }

    public function letGo()
    {
        m::close();
    }

    function it_is_initializable()
    {
        $this->shouldHaveType(Journal::class);
    }

    function it_handles_redmine_journals_and_transforms_them_into_comments()
    {
       $entry = [
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
        ];

        $expected = "On Monday, April 27th 2015 15:55:47, Albert Einstein wrote:\n> A comment //someone// made with `code`\nand:\n - changed done from 90% to 100%";

        $this->transform($entry, 1)->shouldReturn($expected);
    }

    function it_handles_redmine_journals_and_transforms_custom_fields_into_comment_addons()
    {
        $container = new Container();
        $container['redmine'] = function ($c) {
            return m::mock('Facade');
        };
        $container['redmine']
        ->shouldReceive('getCustomFieldById')
        ->with(1)
        ->once()
        ->andReturn('Billed');
        $this->beConstructedWith($container);

        $entry = [
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
        ];

        $expected = "On Monday, April 27th 2015 15:55:47, Albert Einstein wrote:\n> A comment someone made\nand:\n - changed \"Billed\" from \"old\" to \"new\"";

        $this->transform($entry, 1)->shouldReturn($expected);
    }

    function it_transforms_unknown_redmine_journal_entries_into_serialized_data()
    {
        $entry = [
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
        ];

        ob_start();
        $expected = "On Monday, April 27th 2015 15:55:47, Albert Einstein wrote:\n> A comment someone made\nand:\n - changed another property I don't know about: a:3:{s:8:\"property\";s:4:\"attr\";s:4:\"name\";s:7:\"unknown\";s:16:\"unknown_property\";b:1;}";
        $this->transform($entry, 1)->shouldReturn($expected);
        ob_end_clean();
    }
}
