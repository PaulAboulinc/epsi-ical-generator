<?php

$thisMonday = new \DateTime('Monday this week');
$end = (clone($thisMonday))->modify('+56 days');
$interval = new DateInterval('P7D');
$dates = new DatePeriod($thisMonday, $interval ,$end);

$pseudo = $_GET['pseudo'];
$filename = '/var/www/'.$pseudo.'.ics';

$fileExist = file_exists($filename);
$hasBeenUpdatedThisHour = $fileExist && (time() - filemtime($filename)) < 3600;

if (!empty($pseudo) && !$hasBeenUpdatedThisHour) {
    $classes = getClasses($dates, $pseudo);
    $icsContent = getCalendarContent($classes);

    file_put_contents($filename, $icsContent);
}

header('Content-type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename=calendar.ics');
echo file_get_contents($filename);
exit;

/**
 * @return bool|string
 */
function getResponse($date, $pseudo)
{
    $endpoint = 'https://edtmobiliteng.wigorservices.net//WebPsDyn.aspx';
    $params = [
        'action' => 'posEDTBEECOME',
        'Tel' => $pseudo,
        'date' => $date,
        'serverid' => 'C',
    ];

    $url = $endpoint . '?' . http_build_query($params);

    $options = [
        CURLOPT_RETURNTRANSFER => true,   // return web page
        CURLOPT_HEADER => false,  // don't return headers
        CURLOPT_FOLLOWLOCATION => true,   // follow redirects
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, $options);

    $content = curl_exec($ch);

    curl_close($ch);

    return $content;
}

/**
 * @param DatePeriod $dates
 * @return array
 */
function getClasses($dates, $pseudo)
{
    $classes = [];
    foreach ($dates as $date) {
        $response = getResponse($date->format('m/d/Y'), $pseudo);

        while ($classHTML = getStringBetween($response, '<DIV class="Case"', '</table></DIV></div></div>')) {
            list($DTSTART, $DTEND) = getFormattedClassDate($date, $classHTML);

            $description = getStringBetween($classHTML, '<td colspan="2" class="TCProf" height="1.2em">', '</td>');
            $description = str_replace('<br/>', ' - ', $description);
            $description .= ' | Lien teams : ' . getStringBetween($classHTML, '<div class="Teams"><a href="', '"');

            $classes[] = [
                'DTSTART' => $DTSTART,
                'DTEND' => $DTEND,
                'SUMMARY' => getClassName($classHTML),
                'LOCATION' => getStringBetween($classHTML, '<td class="TCSalle">', '</td>'),
                'DESCRIPTION' => $description,
            ];

            $response = str_replace('<DIV class="Case"' . $classHTML . '</table></DIV></div></div>', "", $response);
        }
    }

    return $classes;
}

/**
 * @param DateTimeInterface $monday
 * @param string $classHTML
 */
function getFormattedClassDate($monday, $classHTML)
{
    $leftValue = floatval(getStringBetween($classHTML, 'left:', '%;'));
    $day = round((intval($leftValue * 100) - 8372) / 1940) - 1;
    $datesHTML = explode(' - ', getStringBetween($classHTML, '<td class="TChdeb">', '</td>'));
    $startTime = explode(':', $datesHTML[0]);
    $endDateTime = explode(':', $datesHTML[1]);

    $startDate = clone($monday);
    $startDate->modify("+$day days");
    $startDate->setTime(intval($startTime[0]), intval($startTime[1]));

    $endDate = clone($monday);
    $endDate->modify("+$day days");
    $endDate->setTime(intval($endDateTime[0]), intval($endDateTime[1]));

    return [
        $startDate->format('Ymd') . 'T' . $startDate->format('His'),
        $endDate->format('Ymd') . 'T' . $endDate->format('His'),
    ];
}

/**
 * @param $classHTML
 * @return string
 */
function getClassName($classHTML)
{
    $presence = getStringBetween($classHTML, '<div class="Presence">', '</div>');
    $classHTML = str_replace('<div class="Presence">' . $presence . '</div>', '', $classHTML);

    $urlTeams = getStringBetween($classHTML, '<div class="Teams">', '</div>');
    $classHTML = str_replace('<div class="Teams">' . $urlTeams . '</div>', '', $classHTML);

    $summary = getStringBetween($classHTML, '<td class="TCase" colspan="2" style="font-size:100%;">', '</td>');

    return str_replace(["\n", "\r"], ' ', $summary);
}

/**
 * @param $string
 * @param $start
 * @param $end
 * @return string
 */
function getStringBetween($string, $start, $end)
{
    $string = ' ' . $string;
    $ini = strpos($string, $start);
    if ($ini == 0) return '';
    $ini += strlen($start);
    $len = strpos($string, $end, $ini) - $ini;

    $substring = substr($string, $ini, $len);

    if (is_string($substring)) {
        return html_entity_decode($substring);
    }

    return '';
}

function getCalendarContent($classes)
{
    $calendar = "BEGIN:VCALENDAR\n";
    $calendar .= "VERSION:2.0\n";
    $calendar .= "PRODID:-//ZContent.net//Zap Calendar 1.0//EN\n";
    $calendar .= "CALSCALE:GREGORIAN\n";
    $calendar .= "METHOD:PUBLISH\n";
    $calendar .= "X-WR-TIMEZONE:Europe/Paris\n";
    $calendar .= "X-WR-CALNAME:Epsi agenda\n";
    $calendar .= "X-WR-CALDESC:Epsi agenda\n";

    foreach ($classes as $class) {
        $calendar .= "BEGIN:VEVENT\n";
        $calendar .= "STATUS:CONFIRMED\n";
        $calendar .= "UID:" . uniqid() . "\n";
        $calendar .= "DTSTAMP:" . (new DateTime())->format('Ymd\THis\Z') . "\n";

        foreach ($class as $key => $value) {
            $calendar .= "$key:$value\n";
        }

        $calendar .= "END:VEVENT\n";
    }

    $calendar .= "END:VCALENDAR\n";

    return $calendar;
}