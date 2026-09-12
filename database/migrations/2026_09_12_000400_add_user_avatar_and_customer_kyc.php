<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar')->nullable()->after('email');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('person_type')->default('individual')->after('mobile');
            $table->string('national_id', 20)->nullable()->after('person_type');
            $table->string('father_name')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('birth_certificate_no')->nullable();
            $table->string('birth_place')->nullable();
            $table->string('gender', 16)->nullable();
            $table->string('email')->nullable();
            $table->string('province')->nullable();
            $table->string('city')->nullable();
            $table->text('address')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('sheba', 34)->nullable();
            $table->string('bank_name')->nullable();
            $table->string('account_number')->nullable();
            $table->string('account_holder')->nullable();
            $table->string('shop_name')->nullable();
            $table->string('shop_category')->nullable();
            $table->string('website')->nullable();
            $table->string('company_name')->nullable();
            $table->string('registration_no')->nullable();
            $table->string('economic_code')->nullable();
            $table->string('legal_national_id')->nullable();
            $table->json('documents')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('avatar');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'person_type',
                'national_id',
                'father_name',
                'birth_date',
                'birth_certificate_no',
                'birth_place',
                'gender',
                'email',
                'province',
                'city',
                'address',
                'postal_code',
                'sheba',
                'bank_name',
                'account_number',
                'account_holder',
                'shop_name',
                'shop_category',
                'website',
                'company_name',
                'registration_no',
                'economic_code',
                'legal_national_id',
                'documents',
            ]);
        });
    }
};
