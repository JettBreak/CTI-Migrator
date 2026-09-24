<?php

namespace App\Tests\Controller;

use App\Core\CoreAccountGateway;
use App\Core\InMemoryCoreAccountGateway;
use App\Entity\MigrationBatch;
use App\Enum\BatchStatus;
use App\Tests\AppTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class BatchWorkflowTest extends AppTestCase
{
    /** The source export with new_account filled in. Account 001-004568921 is linked to cards 1001 and 1006. */
    private const VALID_CSV = <<<'CSV'
        card_ref,card_number,cardholder,customer_id,current_account,new_account
        1001,"5412 86•• •••• 8821","Maria L. Santos",CUS-100284,001-004568921,009-003821447
        1006,"5412 86•• •••• 9902","Maria L. Santos",CUS-100284,001-004568921,009-003821447
        1002,"5412 86•• •••• 7310","Jonathan D. Cruz",CUS-100316,001-009713450,009-003821448
        ,,,,001-005688102,009-003821449
        CSV;

    /** @var list<string> */
    private array $tempFiles = [];
    /** Last "batch-action" CSRF token seen; tokens are per session, so it stays valid for other batches. */
    private string $token = '';

    protected function tearDown(): void
    {
        array_map('unlink', array_filter($this->tempFiles, 'is_file'));
        parent::tearDown();
    }

    public function testMakerCheckerHappyPathRenamesAccountsInCore(): void
    {
        $this->loginAs('officer');
        $batch = $this->upload(self::VALID_CSV);
        self::assertSame(BatchStatus::Validated, $batch->getStatus());
        self::assertSame(3, $batch->accountCount());
        self::assertSame(2, $batch->getRows()->first()->getLinkedCards());
        self::assertSelectorTextContains('tbody', '5412 86•• •••• 8821');
        self::assertSelectorTextContains('tbody', 'Ready to rename');

        $this->post($batch, 'submit');
        self::assertSame(BatchStatus::AwaitingApproval, $this->reload($batch)->getStatus());

        // The maker cannot approve their own batch.
        $this->post($batch, 'approve', expectRedirect: false);
        self::assertResponseStatusCodeSame(403);

        $this->loginAs('approver');
        $this->post($batch, 'approve');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash.success', 'accounts renamed in core');

        $batch = $this->reload($batch);
        self::assertSame(BatchStatus::Completed, $batch->getStatus());
        self::assertSame('approver', $batch->getReviewedBy());

        $core = $this->core();
        self::assertSame([
            ['from' => '001-004568921', 'to' => '009-003821447', 'user' => 'approver'],
            ['from' => '001-009713450', 'to' => '009-003821448', 'user' => 'approver'],
            ['from' => '001-005688102', 'to' => '009-003821449', 'user' => 'approver'],
        ], $core->renames, 'One rename per account, even though 001-004568921 is on two rows.');
        self::assertSame('009-003821447', $core->accounts[2001]['no']);

        // Both cards linked to the renamed account follow it, and keep the old number in <OLDACCTNO>.
        $mariaLinks = array_values(array_filter($core->links, static fn ($l) => 2001 === $l['account']));
        self::assertCount(2, $mariaLinks);
        foreach ($mariaLinks as $link) {
            self::assertSame('<ACCTNO>009-003821447</><ACCTTYPE>SAVINGS ACCOUNT</><ACCT>SA</><OLDACCTNO>001-004568921</>', $link['xml']);
        }

        self::assertSelectorTextContains('.audit', 'Uploaded by officer');
        self::assertSelectorTextContains('.audit', '3 account(s) renamed in core, 4 card link(s) updated');

        $this->client->request('GET', sprintf('/migration/batches/%d/report.csv', $batch->getId()));
        self::assertResponseIsSuccessful();
        $report = $this->client->getInternalResponse()->getContent();
        self::assertStringStartsWith("card_ref,card_number,cardholder,old_account,new_account,linked_cards,migration_status,applied_at,approved_by\n", $report);
        self::assertStringContainsString('1001,"5412 86•• •••• 8821","Maria L. Santos",001-004568921,009-003821447,2,Completed,', $report);

        // Once applied, nobody can act on it again.
        $this->post($batch, 'approve', expectRedirect: false);
        self::assertResponseStatusCodeSame(403);
    }

    public function testRowsThatDoNotFitCoreBlockTheBatch(): void
    {
        $this->loginAs('officer');
        $batch = $this->upload(<<<'CSV'
            card_ref,current_account,new_account
            1001,001-004568921,009-003821460
            ,001-000000000,009-100000001
            1002,001-005688102,009-100000002
            ,001-002421965,009-100000003
            ,001-002421965,009-100000004
            ,001-008245779,009-100000003
            abc,001-009713450,009-100000005
            ,001-009713450,001-009713450
            ,001-009713450,0123456789012345678901234567890
            ,001-009713450,
            CSV);

        self::assertSame(BatchStatus::Invalid, $batch->getStatus());
        $errors = array_map(static fn ($row) => implode(' ', $row->getErrors()), $batch->getRows()->toArray());
        self::assertStringContainsString('new_account already exists in core', $errors[0]);
        self::assertStringContainsString('current_account not found in core', $errors[1]);
        self::assertStringContainsString('The card in card_ref is not linked to current_account', $errors[2]);
        self::assertSame('', $errors[3], 'first mapping for 001-002421965 is fine on its own');
        self::assertStringContainsString('current_account is mapped to 009-100000003 on line 5', $errors[4]);
        self::assertStringContainsString('new_account is already assigned to 001-002421965 on line 5', $errors[5]);
        self::assertStringContainsString('card_ref must be the numeric', $errors[6]);
        self::assertStringContainsString('same as current_account', $errors[7]);
        self::assertStringContainsString('longer than 30 characters', $errors[8]);
        self::assertStringContainsString('both required', $errors[9]);

        self::assertSelectorTextContains('body', "This batch can't be submitted");
        self::assertSelectorNotExists('form[action$="/submit"]');

        // Even with a valid token (picked up from another batch's page), the voter refuses.
        $this->view($this->upload(self::VALID_CSV));
        $this->post($batch, 'submit', expectRedirect: false);
        self::assertResponseStatusCodeSame(403);
    }

    public function testCoreChangesAfterValidationFailTheWholeBatchWithoutWriting(): void
    {
        $this->loginAs('officer');
        $batch = $this->upload(self::VALID_CSV);
        $this->post($batch, 'submit');

        // Someone creates an account with one of the new numbers while the batch waits for approval.
        $this->core()->addAccount(2999, '009-003821449');

        $this->loginAs('approver');
        $this->post($batch, 'approve');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash.error', '1 row(s) no longer match live core data.');

        $batch = $this->reload($batch);
        self::assertSame(BatchStatus::Failed, $batch->getStatus());
        self::assertSame([], $this->core()->renames, 'All-or-nothing: the other accounts must not be renamed either.');
        self::assertSame('001-004568921', $this->core()->accounts[2001]['no']);
        self::assertNull($batch->getRows()->first()->getAppliedAt());
    }

    public function testApproverCanRejectWithANote(): void
    {
        $this->loginAs('officer');
        $batch = $this->upload(self::VALID_CSV);
        $this->post($batch, 'submit');

        $this->loginAs('approver');
        $this->post($batch, 'reject', ['note' => 'Wrong branch file']);

        $batch = $this->reload($batch);
        self::assertSame(BatchStatus::Rejected, $batch->getStatus());
        self::assertSame('Wrong branch file', $batch->getReviewNote());
        self::assertSame([], $this->core()->renames);
    }

    public function testApproverCannotApproveABatchTheyUploaded(): void
    {
        $this->loginAs('approver');
        $batch = $this->upload(self::VALID_CSV);
        $this->post($batch, 'submit');
        $this->post($batch, 'approve', expectRedirect: false);
        self::assertResponseStatusCodeSame(403);
    }

    public function testActionsRequireACsrfToken(): void
    {
        $this->loginAs('officer');
        $batch = $this->upload(self::VALID_CSV);
        $this->client->request('POST', sprintf('/migration/batches/%d/submit', $batch->getId()), ['_token' => 'forged']);
        self::assertResponseRedirects('/login'); // an invalid CSRF token is treated as an authentication failure
        self::assertSame(BatchStatus::Validated, $this->reload($batch)->getStatus());
    }

    public function testUnreadableFilesAreRejectedWithoutCreatingABatch(): void
    {
        $this->loginAs('officer');
        $this->submitUpload("card_ref,current_account\n1,2\n");
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-errors', 'Missing column(s): new_account');

        $this->submitUpload('not a csv', 'notes.txt');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-errors', 'Upload a .csv file.');

        self::assertSame(0, static::getContainer()->get(EntityManagerInterface::class)->getRepository(MigrationBatch::class)->count([]));
    }

    private function upload(string $csv): MigrationBatch
    {
        $this->submitUpload($csv);
        self::assertResponseStatusCodeSame(303);
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        preg_match('#/migration/batches/(\d+)#', $this->client->getRequest()->getUri(), $m);

        return $this->find((int) $m[1]);
    }

    private function submitUpload(string $contents, string $name = 'mapping.csv'): void
    {
        $dir = sys_get_temp_dir().'/ucpb-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $path = $dir.'/'.$name;
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        $form = $this->client->request('GET', '/migration')->selectButton('Upload and validate')->form();
        $form['mapping_upload[file]']->upload($path);
        $this->client->submit($form);
    }

    /** @param array<string, string> $extra */
    private function post(MigrationBatch $batch, string $action, array $extra = [], bool $expectRedirect = true): void
    {
        $this->view($batch);
        $this->client->request('POST', sprintf('/migration/batches/%d/%s', $batch->getId(), $action), ['_token' => $this->token] + $extra);
        if ($expectRedirect) {
            self::assertResponseStatusCodeSame(303);
        }
    }

    /** Opens the batch page, keeping its CSRF token if it shows any action form. */
    private function view(MigrationBatch $batch): void
    {
        $crawler = $this->client->request('GET', sprintf('/migration/batches/%d', $batch->getId()));
        if ($crawler->filter('input[name="_token"]')->count()) {
            $this->token = $crawler->filter('input[name="_token"]')->attr('value');
        }
    }

    private function find(int $id): MigrationBatch
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->find(MigrationBatch::class, $id);
    }

    private function reload(MigrationBatch $batch): MigrationBatch
    {
        return $this->find($batch->getId());
    }

    private function core(): InMemoryCoreAccountGateway
    {
        return static::getContainer()->get(CoreAccountGateway::class);
    }
}
