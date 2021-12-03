<?php
namespace Tests\Unit;

use App\Exceptions\CheckoutNotAllowed;
use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Company;
use App\Models\Location;
use App\Models\User;
use App\Models\Category;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Tests\Unit\BaseTest;
use App\Models\Component;
use App\Models\ActionLog;


class AssetTest extends BaseTest
{
    /**
     * @var \UnitTester
     */
    protected $tester;

    // public function testAutoIncrementMixed()
    // {
    //     $expected = '123411';
    //     $next = Asset::nextAutoIncrement(
    //         collect([
    //             ['asset_tag' => '0012345'],
    //             ['asset_tag' => 'WTF00134'],
    //             ['asset_tag' => 'WTF-745'],
    //             ['asset_tag' => '0012346'],
    //             ['asset_tag' => '00123410'],
    //             ['asset_tag' => 'U8T7597h77'],
    //         ])
    //     );

    //     \Log::debug('Next: '.$next);
    //     $this->assertEquals($expected, $next);
    // }


    /**
     * @test
     */
    public function testWarrantyExpiresAttribute()
    {

        $category = Category::factory()->assetLaptopCategory()->create();

        dd($category);
        $model = AssetModel::factory()
        ->create(
            [
                'category_id' => $category->id
            ]
            );

        $asset = Asset::factory()
        ->create(
            [
                'model_id' => $model->id,                       
            ]);

        dd($asset);


        // $asset = Asset::factory()
        // ->create(
        //     [
        //         'model_id' => AssetModel::factory()
        //             ->create(
        //                 [
        //                     'category_id' => Category::factory()->assetLaptopCategory()->create()->id
        //                 ]
        //         )->id,                       
        //     ]);

        // dd($asset);


        // $asset = Asset::factory()
        //     ->create(
        //         [
        //             'model_id' => AssetModel::factory()
        //                 ->create(
        //                     [
        //                         'category_id' => Category::factory()->assetLaptopCategory()->create()->id
        //                     ]
        //             )->id,                       
        //         ]);

        $asset->purchase_date = Carbon::createFromDate(2017, 1, 1)->hour(0)->minute(0)->second(0);
        $asset->warranty_months = 24;
        $asset->save();

        $saved_asset = Asset::find($asset->id);

        $this->tester->assertInstanceOf(\DateTime::class, $saved_asset->purchase_date);
        $this->tester->assertEquals(
             Carbon::createFromDate(2017, 1, 1)->format('Y-m-d'),
             $saved_asset->purchase_date->format('Y-m-d')
         );
        $this->tester->assertEquals(
             Carbon::createFromDate(2017, 1, 1)->setTime(0, 0, 0),
             $saved_asset->purchase_date
         );
        $this->tester->assertEquals(24, $saved_asset->warranty_months);
        $this->tester->assertInstanceOf(\DateTime::class, $saved_asset->warranty_expires);
        $this->tester->assertEquals(
             Carbon::createFromDate(2019, 1, 1)->format('Y-m-d'),
             $saved_asset->warranty_expires->format('Y-m-d')
         );
        $this->tester->assertEquals(
             Carbon::createFromDate(2019, 1, 1)->setTime(0, 0, 0),
             $saved_asset->warranty_expires
         );
    }



    public function testAnAssetHasRelationships()
    {
        $asset = Asset::factory()->laptopMbp()
             ->create([
                 'model_id' => $this->createValidAssetModel()->id,
                 'company_id' => $this->createValidCompany()->id,
                 'supplier_id' => $this->createValidSupplier()->id,
             ]);
        $this->assertInstanceOf(AssetModel::class, $asset->model);
        $this->assertInstanceOf(Company::class, $asset->company);
        $this->assertInstanceOf(App\Models\Depreciation::class, $asset->depreciation);
        $this->assertInstanceOf(App\Models\Statuslabel::class, $asset->assetstatus);
        $this->assertInstanceOf(App\Models\Supplier::class, $asset->supplier);
    }

    public function testAnAssetCanBeAvailableForCheckout()
    {
        // Logic: If the asset is not assigned to anyone,
        // and the statuslabel type is "deployable"
        // and the asset is not deleted
        // Then it is available for checkout

        // An asset assigned to someone should not be available for checkout.
        $assetAssigned = Asset::factory()->laptopMbp()->assignedToUser()
             ->create(['model_id' => $this->createValidAssetModel()]);
        $this->assertFalse($assetAssigned->availableForCheckout());

        // An asset with a non deployable statuslabel should not be available for checkout.
        $assetUndeployable = Asset::factory()->create([
             'status_id' => $this->createValidStatuslabel('archived')->id,
             'model_id' => $this->createValidAssetModel(),
         ]);

        $this->assertFalse($assetUndeployable->availableForCheckout());

        // An asset that has been deleted is not avaiable for checkout.
        $assetDeleted = Asset::factory()->deleted()->create([
             'model_id' => $this->createValidAssetModel(),
         ]);
        $this->assertFalse($assetDeleted->availableForCheckout());

        // A ready to deploy asset that isn't assigned to anyone is available for checkout
        $asset = Asset::factory()->create([
             'status_id' => $this->createValidStatuslabel('rtd')->id,
             'model_id' => $this->createValidAssetModel(),
         ]);
        $this->assertTrue($asset->availableForCheckout());
    }

    public function testAnAssetCanHaveComponents()
    {
        $asset = $this->createValidAsset();

        $components = Component::factory()->count(5)->ramCrucial4()->create([
             'category_id' => $this->createValidCategory('component-hdd-category')->id,
         ]);

        $components->each(function ($component) use ($asset) {
            $component->assets()->attach($component, [
                 'asset_id'=>$asset->id,
             ]);
        });
        $this->assertInstanceOf(Component::class, $asset->components()->first());
        $this->assertCount(5, $asset->components);
    }

    public function testAnAssetCanHaveUploads()
    {
        $asset = $this->createValidAsset([
            'supplier_id' => $this->createValidSupplier()->id,
         ]);
        $this->assertCount(0, $asset->uploads);
        // This is wrong
        Actionlog::factory()->count('asset-upload')->create(
            ['item_id' => $asset->id]
        );
        $this->assertCount(1, $asset->fresh()->uploads);
    }

    // Helper Method for checking in assets.... We should extract this to the model or a trait.

    private function checkin($asset, $target)
    {
        $asset->expected_checkin = null;
        $asset->last_checkout = null;
        $asset->assigned_to = null;
        $asset->assigned_type = null;
        $asset->location_id = $asset->rtd_location_id;
        $asset->assignedTo()->disassociate($asset);
        $asset->accepted = null;
        $asset->save();
        $asset->logCheckin($target, 'Test Checkin');
    }

    public function testAnAssetCanBeCheckedOut()
    {
        // This tests Asset::checkOut(), Asset::assignedTo(), Asset::assignedAssets(), Asset::assetLoc(), Asset::assignedType(), defaultLoc()
        $asset = $this->createValidAsset();
        $adminUser = $this->signIn();

        $target = User::factory()->create([
             'location_id' => Location::factory()->create(),
         ]);
        // An Asset Can be checked out to a user, and this should be logged.
        $asset->checkOut($target, $adminUser);
        $asset->save();
        $this->assertInstanceOf(User::class, $asset->assignedTo);

        $this->assertEquals($asset->location->id, $target->userLoc->id);
        $this->assertEquals('user', $asset->assignedType());
        $this->assertEquals($asset->defaultLoc->id, $asset->rtd_location_id);
        $this->tester->seeRecord('action_logs', [
             'action_type' => 'checkout',
             'target_type'   => get_class($target),
             'target_id'     => $target->id,
         ]);

        $this->tester->seeRecord('assets', [
             'id' => $asset->id,
             'assigned_to' => $target->id,
             'assigned_type' => User::class,
         ]);

        $this->checkin($asset, $target);
        $this->assertNull($asset->fresh()->assignedTo);

        $this->tester->seeRecord('action_logs', [
             'action_type' => 'checkin from',
             'target_type'   => get_class($target),
             'target_id'     => $target->id,
         ]);

        $this->tester->seeRecord('assets', [
             'id' => $asset->id,
             'assigned_to' => null,
             'assigned_type' => null,
         ]);

        // An Asset Can be checked out to a asset, and this should be logged.
        $target = $this->createValidAsset();

        $asset->checkOut($target, $adminUser);
        $asset->save();
        $this->assertInstanceOf(App\Models\Asset::class, $asset->fresh()->assignedTo);
        $this->assertEquals($asset->fresh()->location->id, $target->fresh()->location->id);
        $this->assertEquals('asset', $asset->assignedType());
        $this->assertEquals($asset->defaultLoc->id, $asset->rtd_location_id);
        $this->tester->seeRecord('action_logs', [
             'action_type' => 'checkout',
             'target_type'   => get_class($target),
             'target_id'     => $target->id,
         ]);

        $this->assertCount(1, $target->assignedAssets);
        $this->checkin($asset, $target);
        $this->assertNull($asset->fresh()->assignedTo);

        $this->tester->seeRecord('action_logs', [
             'action_type' => 'checkin from',
             'target_type'   => get_class($target),
             'target_id'     => $target->id,
         ]);

        // An Asset cannot be checked out to itself.
        $target = $this->createValidAsset();
        $this->expectException(CheckoutNotAllowed::class);
        $target->checkOut($target, $adminUser);

        // An Asset Can be checked out to a location, and this should be logged.
        $target = $this->createValidLocation();

        $asset->checkOut($target, $adminUser);
        $asset->save();
        $this->assertInstanceOf(App\Models\Location::class, $asset->fresh()->assignedTo);

        $this->assertEquals($asset->fresh()->location->id, $target->fresh()->id);
        $this->assertEquals('location', $asset->assignedType());
        $this->assertEquals($asset->defaultLoc->id, $asset->rtd_location_id);
        $this->tester->seeRecord('action_logs', [
             'action_type' => 'checkout',
             'target_type'   => get_class($target),
             'target_id'     => $target->id,
         ]);
        $this->checkin($asset, $target);
        $this->assertNull($asset->fresh()->assignedTo);

        $this->tester->seeRecord('action_logs', [
             'action_type' => 'checkin from',
             'target_type'   => get_class($target),
             'target_id'     => $target->id,
         ]);
    }

    public function testAnAssetHasMaintenances()
    {
        $asset = $this->createValidAsset();
        \App\Models\AssetMaintenance::factory()->create(['asset_id' => $asset->id]);
        $this->assertCount(1, $asset->assetmaintenances);
    }
}
