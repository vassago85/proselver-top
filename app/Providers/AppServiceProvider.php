<?php

namespace App\Providers;

use App\Models\Company;
use App\Models\Job;
use App\Models\JobDocument;
use App\Observers\JobObserver;
use App\Policies\CompanyPolicy;
use App\Policies\JobDocumentPolicy;
use App\Policies\JobPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::policy(Job::class, JobPolicy::class);
        Gate::policy(Company::class, CompanyPolicy::class);
        Gate::policy(JobDocument::class, JobDocumentPolicy::class);

        // Inventory auto-link is opt-in per environment. While this flag is
        // off (the default) no observer is attached and Job writes don't
        // touch the inventory table — preserving existing dispatch behaviour.
        if (config('features.inventory_link')) {
            Job::observe(JobObserver::class);
        }
    }
}
