<?php
define('CALENDAR_ENGINE', 'PearDate');

require_once 'Calendar/Calendar.php';
require_once 'Calendar/Month/Weekdays.php';
require_once 'Calendar/Day.php';
require_once 'Calendar/Decorator.php';

// accepts multiple entries
class DiaryEvent extends Calendar_Decorator
{
    private $entries = array();

    function __construct($calendar)
    {
        Calendar_Decorator::Calendar_Decorator($calendar);
    }

    function addEntry($entry)
    {
        $this->entries[] = $entry;
    }

    function getEntry()
    {
        $entry = each($this->entries);
        if ($entry) {
            return $entry['value'];
        } else {
            reset($this->entries);
            return false;
        }
    }
}

class MonthPayload_Decorator extends Calendar_Decorator
{
    //Calendar engine
    public $cE;
    public $tableHelper;

    public $year;
    public $month;
    public $firstDay = false;

    function build($events=array())
    {
        $this->tableHelper = new Calendar_Table_Helper($this, $this->firstDay);
        $this->cE = & $this->getEngine();
        $this->year  = $this->thisYear();
        $this->month = $this->thisMonth();

        $daysInMonth = $this->cE->getDaysInMonth($this->year, $this->month);
        for ($i=1; $i<=$daysInMonth; $i++) {
            $Day = new Calendar_Day(2000,1,1); // Create Day with dummy values
            $Day->setTimeStamp($this->cE->dateToStamp($this->year, $this->month, $i));
            $this->children[$i] = new DiaryEvent($Day);
        }
        if (count($events) > 0) {
            $this->setSelection($events);
        }
        /*
        Calendar_Month_Weekdays::buildEmptyDaysBefore();
        Calendar_Month_Weekdays::shiftDays();
        Calendar_Month_Weekdays::buildEmptyDaysAfter();
        Calendar_Month_Weekdays::setWeekMarkers();
        */
        return true;
    }

    function setSelection($events)
    {
        $daysInMonth = $this->cE->getDaysInMonth($this->year, $this->month);
        for ($i=1; $i<=$daysInMonth; $i++) {
            $stamp1 = $this->cE->dateToStamp($this->year, $this->month, $i);
            $stamp2 = $this->cE->dateToStamp($this->year, $this->month, $i+1);
            foreach ($events as $event) {
                if (($stamp1 >= $event['start'] && $stamp1 < $event['end']) ||
                    ($stamp2 > $event['start'] && $stamp2 < $event['end']) ||
                    ($stamp1 <= $event['start'] && $stamp2 > $event['end'])
                ) {
                    $this->children[$i]->addEntry($event);
                    $this->children[$i]->setSelected();
                }
            }
        }
    }

    function fetch()
    {
        $child = each($this->children);
        if ($child) {
            return $child['value'];
        } else {
            reset($this->children);
            return false;
        }
    }
}
/*
class Calendar_Render_MonthlyAgenda_HTML
{
    function __construct() {}

    function toHTML($decorator)
    {
        $table = new HTML_Table(array('class' => 'calendar'));
        $table->setCaption($decorator->thisMonth().' / '.$decorator->thisYear());

        $week_test = 0;
        while ($day = $decorator->fetch()) {

            $data = array();
            $datehelper = new Date($day->thisDay('timestamp'));

            if ($week_test != $datehelper->getWeekOfYear()) {
                $data[0] = $datehelper->getWeekOfYear();
            } else {
                $data[0] = '&nbsp;';
            }
            $week_test = $datehelper->getWeekOfYear();

            $data[1] = $day->thisDay();
            $data[2] = $datehelper->getDayName();

            if ($day->isEmpty()) {
                $data[3] = '&nbsp;';
            } else {
                $i = 0;
                while ($entry = $day->getEntry()) {
                    $i++;

                    $start = new Date($entry['start']);
                    $data[3][$i] = '';
                    if ($start->format('%R') != '00:00') {
                        $data[3][$i] = $start->format('%R') . ' ';
                    }
                    $data[3][$i] .= $entry['desc'];
                    // you can print the time range as well
                }

            }
            $table->addRow($data);
        }

        return $table->toHTML();
    }
}
*/
class VIH_Intranet_Controller_Calendar_Index extends k_Component
{
    private $form;

    function getForm()
    {
        if ($this->form) return $this->form;

        $form = new HTML_QuickForm('calendar', 'get', $this->url());
        $form->addElement('date', 'date', '',
            array('format' => 'M Y',
                   'minYear' => date('Y'),
       			   'maxYear' => date('Y') + 2)
            );
        $defaults = array('date' => date('Y-m-d'));
        $form->setDefaults($defaults);
        $form->addElement('submit', null, 'Hent');

        return ($this->form = $form);
    }

    function getEventsFromCalendarId($calendar_id)
    {
        $this->gdata = new Zend_Gdata_Calendar;

        $query = $this->gdata->newEventQuery();
        $query->setUser($calendar_id);
        $query->setOrderby('starttime');
        $query->setSortOrder('ascending');
        $query->setProjection('full');

        if ($this->query()) {
            $date = $this->query('date');
            $start_date = $date['Y'] . '-' . $date['M'] . '-1';
            $end_date = $date['Y'] . '-' . $date['M'] . '-31';
            $query->setStartMin($start_date);
            $query->setStartMax($end_date);
        } else {
            // this cannot be set if the search should work
            $query->setFutureevents('true');
        }

        try {
            $events = $this->gdata->getCalendarEventFeed($query);
        } catch (Zend_Gdata_App_Exception $e) {
            throw new Exception("Error: " . $e->getMessage());
        }

        foreach ($events as $event) {
            $start = new Date(strtotime($event->when[0]->startTime), new DateTimeZone($this->getTimeZone()));
            $end = new Date(strtotime($event->when[0]->endTime), new DateTimeZone($this->getTimeZone()));

            $e[strtotime($start->format('%Y-%m-%d %T'))] = array(
                'start' => $start->format('%Y-%m-%d %T'),
                'end'   => $end->format('%Y-%m-%d %T'),
                'desc'  => $event->title
            );
        }

        return $e;
    }

    function getCalendars()
    {
        return array(
        	'default' => 'scv5aba9r3r5qcs1m6uddskjic@group.calendar.google.com',
            'foredrag' => '8qle53tc1ogekrkuf3ndam51as@group.calendar.google.com'
        );
    }

    function getEvents()
    {
        $events = array();

        foreach ($this->getCalendars() as $calendar_id) {
            $events = array_merge($events, $this->getEventsFromCalendarId($calendar_id));
        }
        asort($events);
        return $events;
    }

    function renderHtml()
    {
        $this->document->setTitle('Vejle Idrætshøjskoles kalender');

        // adding calendars
        /*
        foreach ($this->getCalendars() as $label => $url) {
            $this->document->addOption($label, $url);
        }
        */
        $this->document->addOption('foredrag', $this->url('foredrag'));

        $date = $this->getForm()->exportValue('date');
        $month = new Calendar_Month_Weekdays($date['Y'], $date['M']);

        $month_decorator = new MonthPayload_Decorator($month);
        $month_decorator->build($this->getEvents());

        return $this->getForm()->toHtml() . $this->getCalendarHTML($month_decorator);
    }

    function getCalendarHTML($decorator)
    {
        $table = new HTML_Table(array('class' => 'calendar'));
        $table->setCaption(ucfirst($this->t($decorator->thisMonth())).' / '.$decorator->thisYear());

        $week_test = 0;
        while ($day = $decorator->fetch()) {

            $data = array();
            $datehelper = new Date($day->thisDay('timestamp'));

            if ($week_test != $datehelper->getWeekOfYear()) {
                $data[0] = $datehelper->getWeekOfYear();
            } else {
                $data[0] = '&nbsp;';
            }
            $week_test = $datehelper->getWeekOfYear();

            $data[1] = $day->thisDay();
            $data[2] = $this->t($datehelper->getDayName());

            if ($day->isEmpty()) {
                $data[3] = '&nbsp;';
            } else {
                $i = 0;
                while ($entry = $day->getEntry()) {
                    $i++;

                    $start = new Date($entry['start']);
                    $data[3][$i] = '';
                    if ($start->format('%R') != '00:00' AND $start->format('%d') == $day->thisDay()) {
                        $data[3][$i] = $start->format('%R') . ' ';
                    }
                    $data[3][$i] .= $entry['desc'];
                    // you can print the time range as well
                }

            }
            $table->addRow($data);
        }

        return $table->toHTML();
    }

    function getTimeZone()
    {
        return 'Europe/Berlin';
    }
}