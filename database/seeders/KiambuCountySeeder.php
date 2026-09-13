<?php

namespace Database\Seeders;

use App\Enums\InstitutionStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\ContentCategory;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\DeviceLearnerAssignment;
use App\Models\ExceptionRequest;
use App\Models\FilteringPolicy;
use App\Models\Incident;
use App\Models\IncidentAction;
use App\Models\Institution;
use App\Models\Laboratory;
use App\Models\Learner;
use App\Models\LearnerGroup;
use App\Models\LearnerSession;
use App\Models\PolicyRule;
use App\Models\ProtectionComponent;
use App\Models\SecurityEvent;
use App\Models\Subcounty;
use App\Models\User;
use App\Models\WebEvent;
use App\Notifications\IncidentCreatedNotification;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Working demonstration data for Kiambu County: one County Director, four
 * Sub-County Directors, five institutions per sub-county with their own Head of
 * Institution and Computer Laboratory Manager, and five records of every
 * operational register behind each school.
 *
 * The seeder is idempotent — every record is keyed on its natural unique
 * columns, so it may be re-run without duplicating the county.
 */
class KiambuCountySeeder extends Seeder
{
    /** Shared demonstration password for every seeded officer account. */
    private const PASSWORD = 'SafeNET2026!';

    private const ITEMS_PER_REGISTER = 5;

    /**
     * @var array<string, array{code: string, schools: list<array{name: string, nemis: string, type: string, ownership: string, location: string, status: InstitutionStatus}>}>
     */
    private const COUNTY = [
        'Kikuyu' => [
            'code' => 'KBU-KKY',
            'schools' => [
                ['name' => 'Kikuyu Township Primary School', 'nemis' => '10101001', 'type' => 'primary', 'ownership' => 'public', 'location' => 'Kikuyu Town, Kikuyu Ward', 'status' => InstitutionStatus::Protected],
                ['name' => 'Gikambura Primary School', 'nemis' => '10101002', 'type' => 'primary', 'ownership' => 'public', 'location' => 'Gikambura, Karai Ward', 'status' => InstitutionStatus::Protected],
                ['name' => 'Karai Junior School', 'nemis' => '10101003', 'type' => 'junior', 'ownership' => 'public', 'location' => 'Karai, Karai Ward', 'status' => InstitutionStatus::AttributionRequired],
                ['name' => 'Renguti Secondary School', 'nemis' => '10101004', 'type' => 'secondary', 'ownership' => 'public', 'location' => 'Renguti, Sigona Ward', 'status' => InstitutionStatus::DeploymentInProgress],
                ['name' => 'Thogoto Special Needs School', 'nemis' => '10101005', 'type' => 'special', 'ownership' => 'private', 'location' => 'Thogoto, Kikuyu Ward', 'status' => InstitutionStatus::Onboarding],
            ],
        ],
        'Ndeiya' => [
            'code' => 'KBU-NDY',
            'schools' => [
                ['name' => 'Ndeiya Primary School', 'nemis' => '10102001', 'type' => 'primary', 'ownership' => 'public', 'location' => 'Ndeiya Centre', 'status' => InstitutionStatus::Protected],
                ['name' => 'Thigio Primary School', 'nemis' => '10102002', 'type' => 'primary', 'ownership' => 'public', 'location' => 'Thigio, Ndeiya Ward', 'status' => InstitutionStatus::Protected],
                ['name' => 'Kamangu Junior School', 'nemis' => '10102003', 'type' => 'junior', 'ownership' => 'public', 'location' => 'Kamangu, Ndeiya Ward', 'status' => InstitutionStatus::AttributionRequired],
                ['name' => 'Nachu Girls Secondary School', 'nemis' => '10102004', 'type' => 'secondary', 'ownership' => 'public', 'location' => 'Nachu, Ndeiya Ward', 'status' => InstitutionStatus::DeploymentInProgress],
                ['name' => 'Ndeiya Special Needs School', 'nemis' => '10102005', 'type' => 'special', 'ownership' => 'private', 'location' => 'Ndeiya Centre', 'status' => InstitutionStatus::Onboarding],
            ],
        ],
        'Kabete' => [
            'code' => 'KBU-KBT',
            'schools' => [
                ['name' => 'Kabete Township Primary School', 'nemis' => '10103001', 'type' => 'primary', 'ownership' => 'public', 'location' => 'Kabete Town', 'status' => InstitutionStatus::Protected],
                ['name' => 'Nyathuna Primary School', 'nemis' => '10103002', 'type' => 'primary', 'ownership' => 'public', 'location' => 'Nyathuna, Nyathuna Ward', 'status' => InstitutionStatus::Protected],
                ['name' => 'Gitaru Junior School', 'nemis' => '10103003', 'type' => 'junior', 'ownership' => 'public', 'location' => 'Gitaru, Gitaru Ward', 'status' => InstitutionStatus::AttributionRequired],
                ['name' => 'Wangige Secondary School', 'nemis' => '10103004', 'type' => 'secondary', 'ownership' => 'public', 'location' => 'Wangige, Kabete Ward', 'status' => InstitutionStatus::DeploymentInProgress],
                ['name' => 'Kabete Special Needs School', 'nemis' => '10103005', 'type' => 'special', 'ownership' => 'private', 'location' => 'Kabete Town', 'status' => InstitutionStatus::Onboarding],
            ],
        ],
        'Limuru' => [
            'code' => 'KBU-LMR',
            'schools' => [
                ['name' => 'Limuru Township Primary School', 'nemis' => '10104001', 'type' => 'primary', 'ownership' => 'public', 'location' => 'Limuru Town', 'status' => InstitutionStatus::Protected],
                ['name' => 'Ngecha Primary School', 'nemis' => '10104002', 'type' => 'primary', 'ownership' => 'public', 'location' => 'Ngecha, Ngecha Tigoni Ward', 'status' => InstitutionStatus::Protected],
                ['name' => 'Rironi Junior School', 'nemis' => '10104003', 'type' => 'junior', 'ownership' => 'public', 'location' => 'Rironi, Limuru East Ward', 'status' => InstitutionStatus::AttributionRequired],
                ['name' => 'Limuru Central Secondary School', 'nemis' => '10104004', 'type' => 'secondary', 'ownership' => 'public', 'location' => 'Limuru Town', 'status' => InstitutionStatus::DeploymentInProgress],
                ['name' => 'Tigoni Special Needs School', 'nemis' => '10104005', 'type' => 'special', 'ownership' => 'private', 'location' => 'Tigoni, Ngecha Tigoni Ward', 'status' => InstitutionStatus::Onboarding],
            ],
        ],
    ];

    /** @var list<array{0: string, 1: string}> */
    private const LEARNER_NAMES = [
        ['Wanjiku', 'Kamau'],
        ['Brian', 'Otieno'],
        ['Amina', 'Hassan'],
        ['Njeri', 'Mwangi'],
        ['Kelvin', 'Kiprono'],
    ];

    /** @var array<string, list<string>> */
    private const GROUP_NAMES = [
        'primary' => ['Grade 4 East', 'Grade 5 East', 'Grade 6 East', 'Grade 6 West', 'Grade 6 North'],
        'junior' => ['Grade 7 East', 'Grade 7 West', 'Grade 8 East', 'Grade 8 West', 'Grade 9 East'],
        'secondary' => ['Form 1 East', 'Form 2 East', 'Form 3 East', 'Form 4 East', 'Form 4 West'],
        'special' => ['Foundation Class', 'Transition Class A', 'Transition Class B', 'Vocational A', 'Vocational B'],
    ];

    /** @var list<array{type: string, health: string}> */
    private const COMPONENTS = [
        ['type' => 'gateway', 'health' => 'healthy'],
        ['type' => 'dns_filter', 'health' => 'healthy'],
        ['type' => 'endpoint_agent', 'health' => 'healthy'],
        ['type' => 'endpoint_agent', 'health' => 'degraded'],
        ['type' => 'browser_extension', 'health' => 'offline'],
    ];

    public function run(): void
    {
        $this->call(DatabaseSeeder::class);

        $categories = ContentCategory::query()->orderBy('id')->get();
        $cde = $this->countyDirector();
        $countyPolicy = $this->countyBaselinePolicy($cde, $categories);

        foreach (self::COUNTY as $subcountyName => $definition) {
            $subcounty = Subcounty::query()->updateOrCreate(
                ['code' => $definition['code']],
                ['name' => $subcountyName, 'is_active' => true],
            );

            $scde = $this->officer(
                'scde.'.Str::lower($subcountyName).'@safernet.go.ke',
                'Sub-County Director, '.$subcountyName,
                UserRole::Scde,
                ['subcounty_id' => $subcounty->id],
            );

            foreach ($definition['schools'] as $school) {
                $this->seedInstitution($school, $subcounty, $cde, $scde, $categories, $countyPolicy);
            }
        }

        $this->command?->info(sprintf(
            'Seeded %d sub-counties, %d institutions, %d officer accounts, %d learners and %d devices.',
            Subcounty::query()->count(),
            Institution::query()->count(),
            User::query()->count(),
            Learner::query()->count(),
            Device::query()->count(),
        ));
    }

    private function countyDirector(): User
    {
        return $this->officer(
            'cde@safernet.go.ke',
            'County Director of Education, Kiambu',
            UserRole::Cde,
            ['subcounty_id' => null, 'institution_id' => null],
        );
    }

    /**
     * @param  array<string, mixed>  $scope
     */
    private function officer(string $email, string $name, UserRole $role, array $scope): User
    {
        return User::query()->withoutGlobalScopes()->updateOrCreate(
            ['email' => $email],
            $scope + [
                'name' => $name,
                'role' => $role,
                'status' => 'active',
                'password' => self::PASSWORD,
                'email_verified_at' => now(),
            ],
        );
    }

    /**
     * @param  Collection<int, ContentCategory>  $categories
     */
    private function countyBaselinePolicy(User $cde, Collection $categories): FilteringPolicy
    {
        $policy = FilteringPolicy::query()->updateOrCreate(
            ['level' => 'county', 'name' => 'Kiambu County Baseline Filtering Policy'],
            [
                'institution_id' => null,
                'learner_group_id' => null,
                'created_by' => $cde->id,
                'status' => 'active',
                'version' => 1,
                'effective_from' => now()->startOfYear(),
            ],
        );

        $categories->each(fn (ContentCategory $category) => PolicyRule::query()->updateOrCreate(
            ['filtering_policy_id' => $policy->id, 'content_category_id' => $category->id],
            [
                'action' => $category->is_high_risk ? 'block' : 'warn',
                'severity' => $category->default_severity,
                'is_locked' => $category->is_high_risk,
                'counts_toward_incidents' => true,
                'threshold_count' => $category->is_high_risk ? 1 : 5,
                'threshold_window_minutes' => 30,
                'notify_immediately' => $category->is_high_risk,
            ],
        ));

        return $policy;
    }

    /**
     * @param  array{name: string, nemis: string, type: string, ownership: string, location: string, status: InstitutionStatus}  $school
     * @param  Collection<int, ContentCategory>  $categories
     */
    private function seedInstitution(
        array $school,
        Subcounty $subcounty,
        User $cde,
        User $scde,
        Collection $categories,
        FilteringPolicy $countyPolicy,
    ): void {
        $slug = Str::of($school['name'])->before(' School')->slug()->toString();
        $prefix = Str::upper(Str::substr(str_replace('-', '', $slug), 0, 6));

        $institution = Institution::query()->updateOrCreate(
            ['nemis_code' => $school['nemis']],
            [
                'subcounty_id' => $subcounty->id,
                'submitted_by' => $scde->id,
                'reviewed_by' => $cde->id,
                'name' => $school['name'],
                'institution_type' => $school['type'],
                'ownership' => $school['ownership'],
                'status' => $school['status'],
                'physical_location' => $school['location'],
                'hoi_name' => 'Head Teacher, '.Str::before($school['name'], ' School'),
                'hoi_email' => 'hoi.'.$slug.'@safernet.go.ke',
                'hoi_phone' => '+2547'.str_pad((string) crc32($slug) % 100000000, 8, '0', STR_PAD_LEFT),
                'learner_population' => 420 + (crc32($slug) % 600),
                'computing_devices_count' => self::ITEMS_PER_REGISTER,
                'laboratories_count' => self::ITEMS_PER_REGISTER,
                'connectivity_type' => 'fibre',
                'submitted_at' => now()->subMonths(4),
                'reviewed_at' => now()->subMonths(4)->addDays(3),
            ],
        );

        $hoi = $this->officer(
            'hoi.'.$slug.'@safernet.go.ke',
            'Head Teacher, '.Str::before($school['name'], ' School'),
            UserRole::Hoi,
            ['subcounty_id' => $subcounty->id, 'institution_id' => $institution->id],
        );

        $clm = $this->officer(
            'clm.'.$slug.'@safernet.go.ke',
            'Laboratory Manager, '.Str::before($school['name'], ' School'),
            UserRole::Clm,
            ['subcounty_id' => $subcounty->id, 'institution_id' => $institution->id],
        );

        $groups = $this->learnerGroups($institution, $school['type']);
        $learners = $this->learners($institution, $groups);
        $laboratories = $this->laboratories($institution, $prefix);
        $deviceGroups = $this->deviceGroups($institution);
        $devices = $this->devices($institution, $prefix, $laboratories, $deviceGroups);

        $this->assignments($institution, $devices, $learners, $clm);
        $sessions = $this->sessions($institution, $devices, $learners, $clm);
        $policies = $this->institutionPolicies($institution, $groups, $categories, $countyPolicy, $hoi);
        $incidents = $this->incidents($institution, $learners, $devices, $categories, $policies->first(), $hoi);

        $this->incidentActions($institution, $incidents, $hoi, $clm);
        $this->webEvents($institution, $sessions, $categories, $policies->first(), $incidents);
        $this->exceptionRequests($institution, $categories, $hoi, $clm, $scde);
        $this->protectionComponents($institution, $devices);
        $this->securityEvents($institution, $devices, $sessions);
        $this->auditTrail($institution, $hoi, $clm);
        $this->notifications($hoi, $incidents);
    }

    /**
     * @return Collection<int, LearnerGroup>
     */
    private function learnerGroups(Institution $institution, string $type): Collection
    {
        return collect(self::GROUP_NAMES[$type])->map(fn (string $name) => LearnerGroup::query()->updateOrCreate(
            ['institution_id' => $institution->id, 'name' => $name, 'academic_year' => (string) now()->year],
            ['grade_level' => Str::of($name)->before(' ')->append(' ')->append(Str::of($name)->explode(' ')[1] ?? '')->trim()->toString()],
        ))->values();
    }

    /**
     * @param  Collection<int, LearnerGroup>  $groups
     * @return Collection<int, Learner>
     */
    private function learners(Institution $institution, Collection $groups): Collection
    {
        return collect(self::LEARNER_NAMES)
            ->map(fn (array $name, int $index) => Learner::query()->updateOrCreate(
                ['institution_id' => $institution->id, 'learner_number' => sprintf('%s-L%03d', $institution->nemis_code, $index + 1)],
                [
                    'learner_group_id' => $groups[$index]->id,
                    'first_name' => $name[0],
                    'last_name' => $name[1],
                    'pin_hash' => Hash::make(sprintf('%04d', 1234 + $index)),
                    'status' => 'active',
                ],
            ))
            ->values();
    }

    /**
     * @return Collection<int, Laboratory>
     */
    private function laboratories(Institution $institution, string $prefix): Collection
    {
        return collect(range(1, self::ITEMS_PER_REGISTER))
            ->map(fn (int $index) => Laboratory::query()->updateOrCreate(
                ['institution_id' => $institution->id, 'name' => 'Computer Laboratory '.$index],
                ['location' => $prefix.' Block '.Str::upper(chr(64 + $index))],
            ))
            ->values();
    }

    /**
     * @return Collection<int, DeviceGroup>
     */
    private function deviceGroups(Institution $institution): Collection
    {
        $names = ['Learner Workstations', 'Staff Workstations', 'Shared Terminals', 'Examination Terminals', 'Library Terminals'];
        $purposes = ['learner', 'staff', 'shared', 'examination', 'library'];

        return collect($names)
            ->map(fn (string $name, int $index) => DeviceGroup::query()->updateOrCreate(
                ['institution_id' => $institution->id, 'name' => $name],
                ['purpose' => $purposes[$index]],
            ))
            ->values();
    }

    /**
     * @param  Collection<int, Laboratory>  $laboratories
     * @param  Collection<int, DeviceGroup>  $deviceGroups
     * @return Collection<int, Device>
     */
    private function devices(Institution $institution, string $prefix, Collection $laboratories, Collection $deviceGroups): Collection
    {
        $statuses = ['active', 'active', 'active', 'offline', 'attention_required'];

        return collect(range(1, self::ITEMS_PER_REGISTER))
            ->map(fn (int $index) => Device::query()->updateOrCreate(
                ['institution_id' => $institution->id, 'asset_tag' => sprintf('%s-PC%03d', $prefix, $index)],
                [
                    'laboratory_id' => $laboratories[$index - 1]->id,
                    'device_group_id' => $deviceGroups[$index - 1]->id,
                    'serial_number' => sprintf('SN-%s-%05d', $prefix, $index * 137),
                    'hostname' => Str::lower($prefix).'-pc-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                    'platform' => 'windows',
                    'usage_type' => $index === 2 ? 'staff' : 'learner',
                    'status' => $statuses[$index - 1],
                    'last_seen_at' => now()->subMinutes($index * 7),
                ],
            ))
            ->values();
    }

    /**
     * @param  Collection<int, Device>  $devices
     * @param  Collection<int, Learner>  $learners
     */
    private function assignments(Institution $institution, Collection $devices, Collection $learners, User $clm): void
    {
        $devices->each(fn (Device $device, int $index) => DeviceLearnerAssignment::query()->updateOrCreate(
            [
                'institution_id' => $institution->id,
                'device_id' => $device->id,
                'learner_id' => $learners[$index]->id,
                'removed_at' => null,
            ],
            ['assigned_by' => $clm->id, 'assigned_at' => now()->subDays(30 - $index)],
        ));
    }

    /**
     * @param  Collection<int, Device>  $devices
     * @param  Collection<int, Learner>  $learners
     * @return Collection<int, LearnerSession>
     */
    private function sessions(Institution $institution, Collection $devices, Collection $learners, User $clm): Collection
    {
        return $devices
            ->map(function (Device $device, int $index) use ($institution, $learners, $clm): LearnerSession {
                $startedAt = now()->subHours($index + 1);
                $ended = $index >= 3;

                return LearnerSession::query()->updateOrCreate(
                    [
                        'institution_id' => $institution->id,
                        'device_id' => $device->id,
                        'learner_id' => $learners[$index]->id,
                    ],
                    [
                        'started_by' => $clm->id,
                        'started_at' => $startedAt,
                        'identity_source' => $index === 0 ? 'school_pin' : 'roster_selection',
                        'last_activity_at' => $startedAt->copy()->addMinutes(35),
                        'ended_at' => $ended ? $startedAt->copy()->addMinutes(50) : null,
                        'end_reason' => $ended ? 'signed_out' : null,
                        'ip_address' => '10.20.'.($index + 1).'.15',
                        'user_agent' => 'SaferNET Agent/1.0 (Windows NT 10.0)',
                    ],
                );
            })
            ->values();
    }

    /**
     * @param  Collection<int, LearnerGroup>  $groups
     * @param  Collection<int, ContentCategory>  $categories
     * @return Collection<int, FilteringPolicy>
     */
    private function institutionPolicies(
        Institution $institution,
        Collection $groups,
        Collection $categories,
        FilteringPolicy $countyPolicy,
        User $hoi,
    ): Collection {
        return collect(range(1, self::ITEMS_PER_REGISTER))
            ->map(function (int $index) use ($institution, $groups, $categories, $countyPolicy, $hoi): FilteringPolicy {
                $isGroupPolicy = $index > 1;

                $policy = FilteringPolicy::query()->updateOrCreate(
                    [
                        'institution_id' => $institution->id,
                        'name' => $isGroupPolicy
                            ? $groups[$index - 1]->name.' Filtering Policy'
                            : $institution->name.' Filtering Policy',
                    ],
                    [
                        'parent_id' => $countyPolicy->id,
                        'learner_group_id' => $isGroupPolicy ? $groups[$index - 1]->id : null,
                        'level' => $isGroupPolicy ? 'group' : 'institution',
                        'created_by' => $hoi->id,
                        'status' => 'active',
                        'version' => 1,
                        'effective_from' => now()->subMonths(2),
                    ],
                );

                $categories->take(self::ITEMS_PER_REGISTER)->each(fn (ContentCategory $category) => PolicyRule::query()->updateOrCreate(
                    ['filtering_policy_id' => $policy->id, 'content_category_id' => $category->id],
                    [
                        'action' => $category->is_high_risk ? 'block' : 'warn',
                        'severity' => $category->default_severity,
                        'is_locked' => $category->is_high_risk,
                        'counts_toward_incidents' => true,
                        'threshold_count' => $category->is_high_risk ? 1 : 5,
                        'threshold_window_minutes' => 30,
                        'notify_immediately' => $category->is_high_risk,
                    ],
                ));

                return $policy;
            })
            ->values();
    }

    /**
     * @param  Collection<int, Learner>  $learners
     * @param  Collection<int, Device>  $devices
     * @param  Collection<int, ContentCategory>  $categories
     * @return Collection<int, Incident>
     */
    private function incidents(
        Institution $institution,
        Collection $learners,
        Collection $devices,
        Collection $categories,
        FilteringPolicy $policy,
        User $hoi,
    ): Collection {
        $severities = ['critical', 'high', 'high', 'medium', 'low'];
        $statuses = ['open', 'open', 'under_review', 'resolved', 'monitored'];

        return collect(range(0, self::ITEMS_PER_REGISTER - 1))
            ->map(function (int $index) use ($institution, $learners, $devices, $categories, $policy, $hoi, $severities, $statuses): Incident {
                $resolved = $statuses[$index] === 'resolved';

                return Incident::query()->updateOrCreate(
                    [
                        'institution_id' => $institution->id,
                        'learner_id' => $learners[$index]->id,
                        'device_id' => $devices[$index]->id,
                        'content_category_id' => $categories[$index]->id,
                    ],
                    [
                        'filtering_policy_id' => $policy->id,
                        'policy_rule_id' => $policy->rules()->where('content_category_id', $categories[$index]->id)->value('id'),
                        'type' => 'filtering_violation',
                        'severity' => $severities[$index],
                        'status' => $statuses[$index],
                        'event_count' => $index + 1,
                        'first_detected_at' => now()->subDays($index + 1),
                        'last_detected_at' => now()->subHours($index + 1),
                        'notified_at' => now()->subHours($index + 1),
                        'assigned_to' => $hoi->id,
                        'resolved_by' => $resolved ? $hoi->id : null,
                        'resolved_at' => $resolved ? now()->subHours($index) : null,
                        'resolution_summary' => $resolved ? 'Guardian contacted and the learner counselled by the school safety team.' : null,
                    ],
                );
            })
            ->values();
    }

    /**
     * @param  Collection<int, Incident>  $incidents
     */
    private function incidentActions(Institution $institution, Collection $incidents, User $hoi, User $clm): void
    {
        $actions = ['acknowledged', 'assigned', 'contacted_guardian', 'counselled', 'monitored'];

        $incidents->each(fn (Incident $incident, int $index) => IncidentAction::query()->updateOrCreate(
            ['institution_id' => $institution->id, 'incident_id' => $incident->id, 'action' => $actions[$index]],
            [
                'actor_id' => $index % 2 === 0 ? $hoi->id : $clm->id,
                'notes' => 'Recorded by the school safety team during the daily review.',
            ],
        ));
    }

    /**
     * @param  Collection<int, LearnerSession>  $sessions
     * @param  Collection<int, ContentCategory>  $categories
     * @param  Collection<int, Incident>  $incidents
     */
    private function webEvents(
        Institution $institution,
        Collection $sessions,
        Collection $categories,
        FilteringPolicy $policy,
        Collection $incidents,
    ): void {
        $domains = ['bet-example.co.ke', 'adult-example.com', 'proxy-example.net', 'social-example.com', 'khanacademy.org'];
        $actions = ['block', 'block', 'block', 'warn', 'allow'];
        $severities = ['high', 'critical', 'high', 'medium', 'low'];

        $sessions->each(function (LearnerSession $session, int $index) use ($institution, $categories, $policy, $incidents, $domains, $actions, $severities): void {
            WebEvent::query()->firstOrCreate(
                [
                    'institution_id' => $institution->id,
                    'learner_session_id' => $session->id,
                    'domain' => $domains[$index],
                ],
                [
                    'event_uuid' => (string) Str::uuid(),
                    'learner_id' => $session->learner_id,
                    'device_id' => $session->device_id,
                    'filtering_policy_id' => $policy->id,
                    'policy_rule_id' => $policy->rules()->where('content_category_id', $categories[$index]->id)->value('id'),
                    'content_category_id' => $categories[$index]->id,
                    'incident_id' => $actions[$index] === 'block' ? $incidents[$index]->id : null,
                    'url' => 'https://'.$domains[$index].'/page/'.($index + 1),
                    'request_kind' => $index === 2 ? 'background' : 'top_level',
                    'action' => $actions[$index],
                    'enforcement_source' => 'endpoint_agent',
                    'severity' => $severities[$index],
                    'reason' => 'category_match',
                    'occurred_at' => now()->subHours($index + 1)->addMinutes(12),
                ],
            );
        });
    }

    /**
     * @param  Collection<int, ContentCategory>  $categories
     */
    private function exceptionRequests(
        Institution $institution,
        Collection $categories,
        User $hoi,
        User $clm,
        User $scde,
    ): void {
        $domains = ['khanacademy.org', 'youtube.com', 'wikipedia.org', 'scratch.mit.edu', 'kenyacurriculum.go.ke'];
        $statuses = ['pending', 'pending', 'approved', 'rejected', 'approved'];

        collect($domains)->each(function (string $domain, int $index) use ($institution, $categories, $hoi, $clm, $scde, $statuses): void {
            $decided = $statuses[$index] !== 'pending';

            ExceptionRequest::query()->updateOrCreate(
                ['institution_id' => $institution->id, 'domain' => $domain],
                [
                    'requested_by' => $index % 2 === 0 ? $hoi->id : $clm->id,
                    'reviewed_by' => $decided ? $scde->id : null,
                    'content_category_id' => $categories[$index]->id,
                    'reason' => 'Required for classroom instruction under the competency-based curriculum.',
                    'status' => $statuses[$index],
                    'expires_at' => $decided ? now()->addMonths(6) : null,
                    'reviewed_at' => $decided ? now()->subDays($index) : null,
                    'review_notes' => $decided ? 'Reviewed against the county filtering standard.' : null,
                ],
            );
        });
    }

    /**
     * @param  Collection<int, Device>  $devices
     */
    private function protectionComponents(Institution $institution, Collection $devices): void
    {
        collect(self::COMPONENTS)->each(fn (array $component, int $index) => ProtectionComponent::query()->updateOrCreate(
            [
                'institution_id' => $institution->id,
                'type' => $component['type'],
                'identifier' => $component['type'].'-'.$institution->nemis_code.'-'.($index + 1),
            ],
            [
                'device_id' => in_array($component['type'], ['endpoint_agent', 'browser_extension'], true) ? $devices[$index]->id : null,
                'version' => '1.4.'.$index,
                'health_status' => $component['health'],
                'last_seen_at' => now()->subMinutes(($index + 1) * 11),
                'policy_synced_at' => now()->subMinutes(($index + 1) * 15),
                'metadata' => ['channel' => 'stable'],
            ],
        ));
    }

    /**
     * @param  Collection<int, Device>  $devices
     * @param  Collection<int, LearnerSession>  $sessions
     */
    private function securityEvents(Institution $institution, Collection $devices, Collection $sessions): void
    {
        $types = ['agent_tamper_attempt', 'policy_sync_failure', 'proxy_detected', 'unattributed_session', 'certificate_error'];
        $severities = ['critical', 'medium', 'high', 'high', 'low'];

        collect($types)->each(fn (string $type, int $index) => SecurityEvent::query()->firstOrCreate(
            ['institution_id' => $institution->id, 'device_id' => $devices[$index]->id, 'type' => $type],
            [
                'event_uuid' => (string) Str::uuid(),
                'learner_session_id' => $sessions[$index]->id,
                'learner_id' => $sessions[$index]->learner_id,
                'severity' => $severities[$index],
                'description' => 'Detected by the endpoint protection agent during routine enforcement.',
                'response' => 'Reported to the county protection service and logged for review.',
                'occurred_at' => now()->subHours($index + 2),
                'metadata' => ['agent_version' => '1.4.'.$index],
            ],
        ));
    }

    private function auditTrail(Institution $institution, User $hoi, User $clm): void
    {
        $events = [
            'institution.reviewed',
            'api.api.v1.learners.store',
            'api.api.v1.devices.store',
            'device.learner_assigned',
            'api.api.v1.filtering-policies.store',
        ];

        // Audit records are immutable, so they may only ever be created.
        collect($events)->each(fn (string $event, int $index) => AuditLog::query()->firstOrCreate(
            ['institution_id' => $institution->id, 'event' => $event],
            [
                'actor_id' => $index % 2 === 0 ? $hoi->id : $clm->id,
                'new_values' => ['method' => 'POST', 'status' => 201],
                'ip_address' => '41.90.64.'.(10 + $index),
                'user_agent' => 'SaferNET Officer Portal',
                'created_at' => now()->subDays($index + 1),
            ],
        ));
    }

    /**
     * @param  Collection<int, Incident>  $incidents
     */
    private function notifications(User $hoi, Collection $incidents): void
    {
        if ($hoi->notifications()->count() >= self::ITEMS_PER_REGISTER) {
            return;
        }

        $incidents->each(function (Incident $incident, int $index) use ($hoi): void {
            $hoi->notifications()->create([
                'id' => (string) Str::uuid(),
                'type' => IncidentCreatedNotification::class,
                'data' => [
                    'incident_id' => $incident->public_id,
                    'severity' => $incident->severity->value,
                    'learner_id' => $incident->learner_id,
                    'device_id' => $incident->device_id,
                ],
                'read_at' => $index >= 3 ? now()->subHours($index) : null,
            ]);
        });
    }
}
