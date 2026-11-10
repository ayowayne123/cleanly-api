<?php

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use Illuminate\Database\Seeder;

class ExpenseCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            'Salaries', 'Transportation', 'Cleaning supplies', 'Equipment',
            'Rent & utilities', 'Marketing', 'Software', 'Payment gateway fees', 'Other',
        ];

        foreach ($categories as $name) {
            ExpenseCategory::updateOrCreate(['name' => $name], ['is_active' => true]);
        }
    }
}