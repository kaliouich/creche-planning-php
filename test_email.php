<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/models/Model.php';
require_once __DIR__ . '/models/User.php';
require_once __DIR__ . '/services/EmailService.php';

$weekNumber = 42;

// Fake children
$children = [
    1 => ['first_name' => 'Léo', 'age_group' => 'PETIT', 'defaults' => []],
    2 => ['first_name' => 'Mia', 'age_group' => 'PETIT', 'defaults' => []],
    3 => ['first_name' => 'Hugo', 'age_group' => 'PETIT', 'defaults' => []],
    4 => ['first_name' => 'Chloé', 'age_group' => 'GRAND', 'defaults' => []],
    5 => ['first_name' => 'Lucas', 'age_group' => 'GRAND', 'defaults' => []],
    6 => ['first_name' => 'Emma', 'age_group' => 'GRAND', 'defaults' => []],
];

// All enrolled by default except we will simulate absences
foreach ($children as $cid => &$c) {
    foreach (['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY'] as $d) {
        foreach (['MORNING', 'AFTERNOON'] as $h) {
            $c['defaults'][$d . '_' . $h] = true;
        }
    }
}
unset($c);

$days = ['MONDAY' => 'Lundi', 'TUESDAY' => 'Mardi', 'WEDNESDAY' => 'Mercredi', 'THURSDAY' => 'Jeudi', 'FRIDAY' => 'Vendredi'];
$halfDays = ['MORNING' => 'Matin', 'AFTERNOON' => 'Après-midi'];

// Generate fake slots
$slots = [];
$assignBySlot = [];
$availBySlot = [];
$presBySlotAndChild = [];
$slotIdCounter = 1;

foreach ($days as $day => $frDay) {
    foreach ($halfDays as $half => $frHalf) {
        $slotId = $slotIdCounter++;
        $type = 'OPEN';
        
        // Simuler fermeture le mercredi après-midi
        if ($day === 'WEDNESDAY' && $half === 'AFTERNOON') {
            $type = 'CLOSED';
        }
        
        $slots[] = [
            'id' => $slotId,
            'day_of_week' => $day,
            'half_day' => $half,
            'slot_type' => $type
        ];
        
        if ($type === 'OPEN') {
            // Assign permanence
            if ($day === 'MONDAY' && $half === 'MORNING') {
                $assignBySlot[$slotId] = ['Maman de Léo', 'Papa de Emma']; // Double perm
            } elseif ($day === 'TUESDAY' && $half === 'AFTERNOON') {
                $assignBySlot[$slotId] = ['Papa de Mia'];
            } elseif ($day === 'FRIDAY' && $half === 'MORNING') {
                $assignBySlot[$slotId] = []; // Équipe / Non rempli
                $availBySlot[$slotId] = ['Maman de Hugo', 'Papa de Lucas']; // Parents dispos
            } else {
                $assignBySlot[$slotId] = ['Maman de Chloé'];
            }
            
            // Simuler des absences
            if ($day === 'MONDAY' && $half === 'MORNING') {
                $presBySlotAndChild[$slotId][1] = false; // Léo absent
                $presBySlotAndChild[$slotId][4] = false; // Chloé absente
            } elseif ($day === 'THURSDAY') {
                $presBySlotAndChild[$slotId][2] = false; // Mia absente toute la journée
            }
        }
    }
}

$html = '<table border="1" cellpadding="10" cellspacing="0" style="border-collapse: collapse; width: 100%; max-width: 900px; font-family: Arial, sans-serif; font-size: 13px;">';
$html .= '<tr style="background-color: #6d28d9; color: white;"><th>Jour</th><th>Matin (8h30 - 12h30)</th><th>Après-midi (14h00 - 18h00)</th></tr>';

foreach ($days as $day => $frDay) {
    $html .= '<tr>';
    $html .= '<td style="background-color: #fef08a; font-weight: bold; width: 15%; text-align: center; color: #6d28d9;">' . $frDay . '</td>';
    
    foreach ($halfDays as $half => $frHalf) {
        $slot = null;
        foreach ($slots as $s) {
            if ($s['day_of_week'] === $day && $s['half_day'] === $half) {
                $slot = $s;
                break;
            }
        }
        
        $html .= '<td style="width: 42.5%; vertical-align: top;">';
        if (!$slot) {
            $html .= '-';
        } elseif ($slot['slot_type'] === 'CLOSED') {
            $html .= '<div style="color: #666; font-style: italic; text-align: center; padding: 10px;">Fermé</div>';
        } else {
            $html .= '<div style="margin-bottom: 8px; text-align: center; background-color: #f5f3ff; border: 1px solid #e9d5ff; padding: 5px; border-radius: 4px;">';
            $html .= '<strong style="color: #6d28d9;">Permanence :</strong><br>';
            $assigns = $assignBySlot[$slot['id']] ?? [];
            if (empty($assigns)) {
                $html .= '<span style="color: #999; font-style: italic;">Équipe / Non rempli</span>';
            } else {
                $html .= '<span style="color: #b45309; font-weight: bold; font-size: 14px;">' . implode(' &amp; ', $assigns) . '</span>';
            }
            $html .= '</div>';
            
            $grandsPres = []; $petitsPres = [];
            $grandsAbs = []; $petitsAbs = [];
            
            foreach ($children as $cid => $c) {
                $isEnrolled = isset($c['defaults'][$day . '_' . $half]);
                $override = isset($presBySlotAndChild[$slot['id']][$cid]) ? $presBySlotAndChild[$slot['id']][$cid] : null;
                $isPresent = ($override !== null) ? $override : $isEnrolled;
                
                if ($isPresent) {
                    if ($c['age_group'] === 'GRAND') $grandsPres[] = $c['first_name'];
                    else $petitsPres[] = $c['first_name'];
                } else {
                    if ($c['age_group'] === 'GRAND') $grandsAbs[] = $c['first_name'];
                    else $petitsAbs[] = $c['first_name'];
                }
            }
            
            $html .= '<div style="font-size: 11px; text-align: left;">';
            $html .= '<div style="margin-bottom: 4px;"><strong style="color: #0284c7;">Grands : ' . count($grandsPres) . ' présents / ' . count($grandsAbs) . ' absents</strong><br>';
            $html .= '<span style="color: #666;">Pr: ' . (empty($grandsPres) ? '-' : implode(', ', $grandsPres)) . ' | Abs: ' . (empty($grandsAbs) ? '-' : implode(', ', $grandsAbs)) . '</span></div>';
            
            $html .= '<div><strong style="color: #059669;">Petits : ' . count($petitsPres) . ' présents / ' . count($petitsAbs) . ' absents</strong><br>';
            $html .= '<span style="color: #666;">Pr: ' . (empty($petitsPres) ? '-' : implode(', ', $petitsPres)) . ' | Abs: ' . (empty($petitsAbs) ? '-' : implode(', ', $petitsAbs)) . '</span></div>';
            
            $avails = $availBySlot[$slot['id']] ?? [];
            if (!empty($avails)) {
                $html .= '<div style="margin-top: 4px; color: #16a34a;"><strong>Parents Dispos: </strong>' . implode(', ', $avails) . '</div>';
            }
            
            $html .= '</div>';
        }
        $html .= '</td>';
    }
    $html .= '</tr>';
}

$html .= '</table>';

// Envoyer à Khalil pour test
$appUrl = 'https://www.lesfruitsdelapassion.fr/planning';
$emailContent = render_published_email("Khalil (Test Simulé)", $weekNumber, $html, $appUrl);

$to = 'khalil.aliouich@gmail.com';
$subject = "[TEST COMPLET] Planning de la semaine $weekNumber publié";

send_email($to, $subject, $emailContent);
echo "Email envoyé à $to\n";
