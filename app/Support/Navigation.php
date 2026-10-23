<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Route;

class Navigation
{
    /**
     * The sidebar navigation. Icons are Iconify names of files in resources/icons.
     *
     * @return list<array{label: string, items: list<array{title: string, href: string, icon: string, create: string|null}>}>
     */
    public static function groups(): array
    {
        $item = fn (string $title, string $route, string $icon, bool $quickAdd = true) => [
            'title' => $title,
            'href' => route($route, absolute: false),
            'icon' => $icon,
            // Where to add a record, for the quick add: the page itself when it is
            // one to add with, else that of the list; null for a page without one.
            'create' => match (true) {
                ! $quickAdd => null,
                str_ends_with($route, '.create') => route($route, absolute: false),
                str_ends_with($route, '.index') && Route::has($create = str_replace('.index', '.create', $route)) => route($create, absolute: false),
                default => null,
            },
        ];

        return [
            ['label' => __('Overview'), 'items' => [
                $item(__('Dashboard'), 'dashboard', 'ic:round-bar-chart'),
                $item(__('Timeline'), 'timeline', 'ic:round-timeline'),
                $item(__('Tips'), 'tips', 'ic:round-auto-awesome'),
            ]],
            ['label' => __('Maintenance'), 'items' => [
                $item(__('Maintenance & repairs'), 'maintenances.index', 'mdi:car-wrench'),
                $item(__('Maintenance plan'), 'maintenance-plan', 'mdi:clipboard-list-outline'),
                $item(__('Reconditioning & washing'), 'reconditionings.index', 'mdi:car-wash'),
                $item(__('Accidents & damage'), 'accidents.index', 'fa7-solid:car-burst'),
                $item(__('Products & accessory'), 'products.index', 'mdi:oil'),
            ]],
            ['label' => __('On the road'), 'items' => [
                $item(__('Refuelings'), 'refuelings.index', 'ic:round-local-gas-station'),
                $item(__('Parking'), 'parking.index', 'fa7-solid:square-parking'),
                $item(__('Toll'), 'toll.index', 'mdi:boom-gate'),
                $item(__('Fines'), 'fines.index', 'maki:police'),
                $item(__('Services'), 'services.index', 'mdi:tow-truck'),
                $item(__('Transport trains & ferry services'), 'ferries.index', 'mdi:train-car-flatbed-car'),
            ]],
            ['label' => __('Trip planner'), 'items' => [
                $item(__('Plan a trip'), 'trips.create', 'mdi:map-marker-path'),
                // Added with "Plan a trip".
                $item(__('My trips'), 'trips.index', 'mdi:map-search-outline', quickAdd: false),
            ]],
            ['label' => __('Fixed costs'), 'items' => [
                $item(__('Insurances'), 'insurances.index', 'mdi:shield-car'),
                $item(__('Road taxes'), 'taxes.index', 'mdi:highway'),
                $item(__('Vignettes'), 'vignettes.index', 'mdi:sticker-text'),
                $item(__('Environmental stickers'), 'environmental-stickers.index', 'fa7-solid:leaf'),
            ]],
        ];
    }

    /**
     * Interface strings the Vue components need, translated for the current locale.
     *
     * @return array<string, string>
     */
    public static function translations(): array
    {
        $keys = [
            'Add a vehicle', 'Add refueling', 'All', 'Apply', 'Average monthly costs', 'Average per month',
            'Average consumption', 'Average price', 'Average speed', 'Back', 'Cancel',
            'Cheapest', 'Close', 'Construction date', 'Cost types', 'Country', 'Create', 'Dashboard', 'Date', 'Date from',
            'Date range:', 'Date until', 'Delete', 'Duplicate', 'Edit', 'Filters', 'Fuel prices', 'Fuel prices abroad', 'Fuel type',
            'Fuel usage by fuel type', 'Gas station', 'Highest price', 'Item', 'Language', 'Latest:', 'Log out', 'Lowest price',
            'Mileage', 'Most expensive', 'Month', 'Month ago', 'Monthly costs', 'More information', 'Most recent costs',
            'My garage', 'Needs attention', 'No costs this month', 'No details available', 'No fuel price data available.',
            'No refuelings found', 'No statuses available to show.', 'Nothing to show', 'Notifications',
            'Number of refuelings', 'Period', 'Predictions', 'Price', 'Purchased with:', 'Refresh fuel prices', 'Reset',
            'Save', 'Select', 'Settings', 'Show', 'Statistics', 'Status', 'Timeline', 'Top gas stations', 'Total refueled', 'Type',
            'Vehicles', 'Visit count', 'Year', 'Year ago', 'years old', 'Are you sure you want to delete this?',
            'Break-even fuel: :km km', 'Break-even full: :km km', 'Current vehicle', 'Costs',
            'To make use of the vehicle manager you need to create your first vehicle.',
            'This is an overview of the vehicles that are in your garage.',
            'No costs in this period', 'Previous: € :price/l', 'Price decrease', 'Price increase',
            // The fuel advice.
            'Fuel advice', 'Generate advice', 'Generate again', 'Generating…', 'Low confidence', 'Medium confidence',
            'High confidence', 'Recommended day', 'Expected refueling', 'Estimated tank level', 'Price in :country',
            'Net savings per full tank', 'Distance from home', 'Super Plus turn (1 in 4 times)', 'Reasons', 'Sources', 'Generated on :date by :model.',
            'Indicative: check the price at the pump.', 'The fuel advice has just been generated.',
            'Get an advice on when and where to refuel, based on the fuel prices and your refuelings.',
            'Your cheapest gas station',
            ':difference below average',
            'Previous advice (:date)',
            'Followed',
            'Not followed',
            'Refueled on :date in :country for :price.',
            ':difference below the average price at the time of the advice (:average): :savings saved.',
            ':difference above the average price at the time of the advice (:average): :savings more.',
            // The notifications: the bell, the page, and dismissing one.
            'Dismiss', 'Show again', 'History', 'Dismissed', 'No notifications', 'All notifications', 'Since :date',
            'Dismissed on :date', 'No longer applies since :date', 'Nothing needs attention right now.',
            'A dismissed notification is listed here, and comes back under Needs attention when it changes.',
            'The notifications that no longer apply are listed here.',
            // The maintenance plan.
            'Checked today', 'Done today', 'Record with price and garage',
            'Maintenance plan', 'Propose the intervals again', 'Fit the plan to your vehicle',
            'The intervals below are the usual ones. Have them proposed for the make, model and engine of your vehicle; the ones you change yourself are kept.',
            'Propose intervals', 'Check the components of your tasks',
            'These components were found in the tasks of your maintenance history. Untick what is not right; nothing is stored before you save.',
            'Link your maintenance history',
            ':count tasks of your maintenance history are not linked to a component yet. Have them sorted out, and check the result before it is stored.',
            'Sort out tasks', 'Components', 'Proposed for your vehicle', 'Set by you', 'Usual interval', 'Every :interval', 'Last:',
            'Not recorded yet: check it, or fill in when it was done.', 'Next:', 'at :km', 'Not on this vehicle', 'Add to the plan',
            'Interval (km)', 'Interval (months)', 'Last done', 'At (km)',
            'Done before it was recorded here? Fill in when; a later maintenance with this component goes first.',
            // The summary of the month.
            'The summary is being written…', 'Nothing has been recorded this month yet.', 'Coming up',
            'Nothing is due in the coming :days days.',
            // The tips.
            'Tips', 'Generate tips', 'The tips have just been generated.',
            'Get tips on maintenance, costs and the season, based on everything that is known about this vehicle.',
            'Important', 'Before :date', 'Based on your data', 'General knowledge', 'Done', 'Not relevant',
            'No tips right now: nothing stands out for this vehicle.', 'Indicative: check a tip before acting on it.',
            // The categories of the tips.
            'Maintenance', 'Season', 'Known issue', 'Costs and savings', 'Resale value', 'Rules and obligations', 'Use',
            // The overview of the tips, and what can be done with a tip.
            'All tips', 'Current tips', 'Kept', 'Keep', 'Remove', 'Kept on :date', 'Done on :date',
            'Keep a tip to look it up here later, without it showing on the dashboard.',
            'The tips you marked as done are listed here.',
            // The trip planner.
            '+:minutes min', ':count places are left out by you.', ':distance km', 'Accident', 'Broken down vehicle', 'Charge',
            'Charging stations',
            'Charging stops based on a consumption of :consumption kWh/100 km and a battery of :tank kWh, charging fast chargers up to 80%.',
            'Dangerous situation', 'Do not stop here',
            'Get tips for this trip from AI: rules in the countries on the way, costs, busy days and what to prepare.', 'Lane closed',
            'Let the planner choose', 'Let the planner choose again', 'Mind',
            'No road works, closures or other traffic are known on this route.', 'Road closed', 'Road works', 'Stop here', 'Traffic',
            'Traffic jam', 'Travel tips', 'about :kwh kWh', 'until further notice',
            'Facilities', 'Shop', 'Charging station', 'Car wash', 'ATM', 'Shower', 'Playground', 'Picnic area', 'Drinking water', 'Tyre air', 'Wi-Fi', 'Delete this trip?', ':name is deleted with its routes and stops. This cannot be undone.',
            'Looking for places to stop along the routes…', 'The routes are ready. The first time in an area, finding the places to stop can take a few minutes.', '1 stop', ':count stops',
            'Hotels', 'Hotel', 'Overnight stay', 'Search on Booking.com', ':count stars', ':duration, until :time', ':minutes min', 'No hotel was found in time along this route: look for a place to spend the night yourself.',
            ':minutes min detour, by exit :exit', 'Cash', 'Contactless', 'Credit card', 'Debit card', 'Estimated price per liter',
            'For :vehicle', 'Loading places…',
            'Looking for places to stop along the routes. The first time in an area this can take a few minutes.',
            'Not all places along this route could be fetched: the stops may not be the best ones.', 'Offers:', 'Opening hours', 'Operator',
            'Payment', 'Planned stop', 'Planning the routes…', 'Price per liter', 'Show surroundings',
            'The places along this route could not be fetched, so no stops are planned. Please plan again later.',
            'Use my current location', 'Website', 'Wheelchair accessible', 'Your location could not be determined.',
            'Now', 'Plan a trip', 'My trips', 'Trips', 'Trip', 'Choose a place from the list.', 'Stop', ':hours h :minutes min', ':minutes min, until :time',
            'Add a stop along the way', 'Alternative :number', 'Arrival', 'Arrive at', 'Depart at', 'Departure',
            'At some breaks there is only a rest area: no coffee or toilet is known there.', 'Break', 'Café', 'Destination',
            'Driving :duration', 'Fast food', 'Fastest route',
            'Fuel stops based on a consumption of :consumption l/100 km from your refuelings and a tank of :tank l.',
            'Fuel stops based on an estimated consumption of :consumption l/100 km and a tank of :tank l.', 'Gas stations', 'Meal',
            'Name (optional)', 'No fuel stops are planned: the tank capacity or the fuel of this vehicle is unknown.',
            'No gas station was found in time along this route: refuel before you leave.',
            'No place for a break was found in time along this route.', 'No places found',
            'No restaurant was found in time along this route.', 'No toilet was found in time along this route.', 'Not planned',
            'Offers: :features', 'Plan a trip to see the routes, the time it takes and where to stop for a break, a meal or fuel.',
            'Plan again', 'Plan trip', 'Planning the routes and the stops along them. The first time in an area this takes about a minute.',
            'Planning…', 'Refuel', 'Rest area', 'Rest areas', 'Restaurants', 'Reverse', 'Searching…', 'Service area', 'Service areas',
            'Start', 'Stay (minutes)', 'Stop along the way', 'Stops', 'Stops :duration', 'Tank at departure (%)', 'Toilets',
            'Toll :distance', 'Toll booth', 'Toll booths', 'Toll roads', 'Traffic delay :duration', 'Vignette: :countries',
            'about :liters l', 'via :roads', 'Coffee', 'Fuel', 'Gas station', 'Parking', 'Restaurant', 'Routes', 'Toilet', 'Vehicle',
            'Remove',
            ':price per liter', 'Fast charger', 'Usually busy', 'Usually a traffic jam',
            'Total travel time :duration',
            'Looking up the traffic along the routes…', 'Fetching the places to stop along the routes (:done/:total)…',
            'Fetching the places to stop along the routes…', 'Fetching what the service areas offer (:done/:total)…',
            'Fetching what the service areas offer…', 'Planning the stops…',
            'Ask for the fuel prices and the traffic again', 'Refresh', 'Fuel prices and traffic of :time',
            'about :amount', 'at least :amount', 'plus toll', ':amount more than the cheapest', 'Energy', 'Toll', 'Total',
            'charging prices are not known', ':liters l at :price', 'The fuel prices are looked up…',
            'about :liters l · no fuel prices known', ':distance of toll road · amount not known', 'Vignette :country',
            'You have one for the dates of this trip', 'As much as the last one you bought',
            'Price not known: none registered yet', 'Known costs',
            'The traffic is left out today: the daily limit of the traffic service is almost reached. Refresh tomorrow to see it.',
            // The settings and the pages for guests.
            '2FA recovery codes', 'A name helps you identify this passkey later.',
            'A new verification link has been sent to the email address you provided during registration.',
            'A new verification link has been sent to your email address.', 'Add a passkey to sign in without a password',
            'Add passkey', 'Added :time', 'Already have an account?', 'Appearance', 'Appearance settings',
            'Are you sure you want to delete your account?',
            'Are you sure you want to remove the passkey ":name"? You will no longer be able to use it to sign in.',
            'Authenticating...', 'Authentication code', 'City', 'Click here to re-send the verification email.', 'Closures',
            'Confirm', 'Confirm password', 'Confirm with passkey', 'Confirming...', 'Continue', 'Continue setup', 'Create account',
            'Create an account', 'Current password', 'Dark', 'Delete account', 'Delete your account and all of its resources',
            'Disable 2FA', 'Don\'t have an account?',
            'Each recovery code can be used once to access your account and will be removed after use. If you need more, click Regenerate codes above.',
            'Email', 'Email address', 'Email password reset link', 'Email verification', 'Enable 2FA',
            'Enable two-factor authentication', 'Ensure your account is using a long, random password to stay secure',
            'Enter recovery code', 'Enter the 6-digit code from your authenticator app',
            'Enter the authentication code provided by your authenticator application.',
            'Enter your details below to create your account', 'Enter your email and password below to log in',
            'Enter your email to receive a password reset link', 'Forgot password', 'Forgot your password?', 'Full name',
            'Hide password', 'Hide recovery codes', 'Incidents', 'Last used :time', 'Light', 'Log in', 'Log in to your account',
            'Manage your passkeys for passwordless sign-in', 'Manage your profile and account settings',
            'Manage your two-factor authentication settings', 'Name', 'New password', 'No passkeys yet',
            'Once your account is deleted, all of its resources and data will also be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.',
            'Optional. Used for the weather your washing advice is based on; without it your location is estimated from your IP address.',
            'Or confirm with password', 'Or continue with email', 'Or, return to', 'Passkey name', 'Passkeys',
            'Passkeys are not supported in this browser.', 'Password',
            'Please confirm access to your account by entering one of your emergency recovery codes.',
            'Please enter your new password below', 'Please proceed with caution, this cannot be undone.',
            'Please verify your email address by clicking on the link we just emailed to you.', 'Profile', 'Profile settings',
            'Recovery code',
            'Recovery codes let you regain access if you lose your 2FA device. Store them in a secure password manager.',
            'Regenerate codes', 'Register', 'Register passkey', 'Registering...', 'Remember me', 'Remove passkey', 'Removing...',
            'Resend verification email', 'Reset password', 'Security', 'Security settings', 'Show password',
            'Sign in with a passkey', 'Sign up', 'System',
            'This is a secure area of the application. Please confirm your password before continuing.',
            'To finish enabling two-factor authentication, scan the QR code or enter the setup key in your authenticator app',
            'Traffic jams', 'Two-factor authentication', 'Two-factor authentication enabled',
            'Two-factor authentication is now enabled. Scan the QR code or enter the setup key in your authenticator app.',
            'Update password', 'Update the appearance settings for your account', 'Update your name and email address',
            'Verify authentication code', 'View recovery codes', 'Warning',
            'When you enable two-factor authentication, you will be prompted for a secure pin during login. This pin can be retrieved from a TOTP-supported application on your phone.',
            'You will be prompted for a secure, random pin during login, which you can retrieve from the TOTP-supported application on your phone.',
            'Your email address is unverified.', 'e.g., MacBook Pro, iPhone', 'log in', 'login using a recovery code',
            'login using an authentication code', 'or you can', 'or, enter the code manually',
        ];

        return collect($keys)->mapWithKeys(fn (string $key) => [$key => __($key)])->all();
    }
}
