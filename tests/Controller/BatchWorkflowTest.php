<?php

namespace App\Tests\Controller;

use App\Core\CoreAccountGateway;
use App\Core\InMemoryCoreAccountGateway;
use App\Entity\MigrationBatch;
use App\Entity\ExportJob;
use App\Entity\MigrationRow;
use App\Enum\BatchStatus;
use App\Enum\ExportKind;
use App\Enum\ExportState;
use App\Repository\MigrationRowRepository;
use App\Tests\AppTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

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
        self::assertSame(2, $this->rows($batch)[0]->getLinkedCards());
        // Dates are recorded in APP_TIMEZONE (Asia/Manila); the batch says so for itself and its rows.
        self::assertSame('UTC+08:00', $batch->getTimezone());
        self::assertSelectorTextContains('tbody', '5412 86•• •••• 8821');
        self::assertSelectorTextContains('tbody', 'Ready to replace');

        $this->post($batch, 'submit');
        self::assertSame(BatchStatus::AwaitingApproval, $this->reload($batch)->getStatus());

        // The maker cannot approve their own batch.
        $this->post($batch, 'approve', expectRedirect: false);
        self::assertResponseStatusCodeSame(403);

        $this->loginAs('approver');
        $this->post($batch, 'approve');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash.success', 'accounts replaced in core');

        $batch = $this->reload($batch);
        self::assertSame(BatchStatus::Completed, $batch->getStatus());
        self::assertSame('approver', $batch->getReviewedBy());

        // Applied in account-number order, two accounts per chunk in the test environment.
        $core = $this->core();
        self::assertSame([
            ['from' => '001-004568921', 'to' => '009-003821447', 'user' => 'approver'],
            ['from' => '001-005688102', 'to' => '009-003821449', 'user' => 'approver'],
            ['from' => '001-009713450', 'to' => '009-003821448', 'user' => 'approver'],
        ], $core->renames, 'One rename per account, even though 001-004568921 is on two rows.');
        self::assertSame('009-003821447', $core->accounts[2001]['no']);

        // Both cards linked to the renamed account follow it, and keep the old number in <OLDACCTNO>.
        $mariaLinks = array_values(array_filter($core->links, static fn ($l) => 2001 === $l['account']));
        self::assertCount(2, $mariaLinks);
        foreach ($mariaLinks as $link) {
            self::assertSame('<ACCTNO>009-003821447</><ACCTTYPE>SAVINGS ACCOUNT</><ACCT>SA</><OLDACCTNO>001-004568921</>', $link['xml']);
        }

        self::assertSelectorTextContains('.audit', 'Uploaded by officer');
        self::assertSelectorTextContains('.audit', '3 account(s) replaced in core, 4 card link(s) updated');

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
        $errors = array_map(static fn ($row) => implode(' ', $row->getErrors()), $this->rows($batch));
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

        self::assertSelectorTextContains('body', '9 row(s) need correction');
        self::assertSelectorNotExists('form[action$="/submit"]');
        self::assertSelectorExists('form[action$="/submit-valid"]', 'the valid row (line 5) can still go ahead on its own');

        // Even with a valid token (picked up from another batch's page), the voter refuses.
        $this->view($this->upload(self::VALID_CSV));
        $this->post($batch, 'submit', expectRedirect: false);
        self::assertResponseStatusCodeSame(403);
    }

    public function testACompletedBatchCanBeRolledBackWithMakerChecker(): void
    {
        $originalLinks = $this->core()->links;
        $batch = $this->completedBatch();

        // Approvers do not request rollbacks, and a reason is required.
        $this->loginAs('approver');
        $this->post($batch, 'rollback', ['reason' => 'Wrong file'], expectRedirect: false);
        self::assertResponseStatusCodeSame(403);
        $this->loginAs('officer');
        $this->post($batch, 'rollback', ['reason' => '  ']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash.error', 'Give a reason for the rollback.');
        self::assertSame(BatchStatus::Completed, $this->reload($batch)->getStatus());

        $this->post($batch, 'rollback', ['reason' => 'Numbers were issued for the wrong branch']);
        $batch = $this->reload($batch);
        self::assertSame(BatchStatus::RollbackRequested, $batch->getStatus());
        self::assertSame('officer', $batch->getRollbackRequestedBy());
        self::assertSame([], $this->core()->restores, 'nothing changes before approval');

        // The requester cannot approve it.
        $this->post($batch, 'rollback/approve', expectRedirect: false);
        self::assertResponseStatusCodeSame(403);

        $this->loginAs('approver');
        $this->post($batch, 'rollback/approve');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash.success', 'Rollback done');

        $batch = $this->reload($batch);
        self::assertSame(BatchStatus::RolledBack, $batch->getStatus());
        self::assertSame(3, $batch->getRolledBackAccountCount());
        self::assertSame(0, $batch->getRollbackSkippedCount());
        self::assertSame('001-004568921', $this->core()->accounts[2001]['no']);
        self::assertSame('001-009713450', $this->core()->accounts[2002]['no']);
        self::assertSame('001-005688102', $this->core()->accounts[2003]['no']);
        self::assertSame($originalLinks, $this->core()->links, 'every card link is exactly as before the replacement');
        foreach ($this->rows($batch) as $row) {
            self::assertNotNull($row->getRolledBackAt());
        }
        self::assertSelectorTextContains('.audit', 'Rollback requested by officer');
        self::assertSelectorTextContains('.audit', 'Rolled back by approver');
        self::assertSelectorTextContains('tbody', 'Rolled back');
        self::assertCount(0, static::getContainer()->get(EntityManagerInterface::class)->getRepository(ExportJob::class)->findBy(['kind' => ExportKind::BatchRollbackSkipped]));
    }

    public function testRollbackLeavesAccountsThatChangedInCoreAloneAndExportsThem(): void
    {
        $batch = $this->completedBatch();
        // Since the replacement, someone gave another account 001-005688102 (an old number of this batch).
        $this->core()->addAccount(2999, '001-005688102');

        $this->loginAs('officer');
        $this->post($batch, 'rollback', ['reason' => 'Test rollback']);
        $this->loginAs('approver');
        $this->post($batch, 'rollback/approve');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash.success', 'were left alone because core changed since');

        $batch = $this->reload($batch);
        self::assertSame(BatchStatus::RolledBack, $batch->getStatus());
        self::assertSame([2, 1], [$batch->getRolledBackAccountCount(), $batch->getRollbackSkippedCount()]);
        self::assertSame('009-003821449', $this->core()->accounts[2003]['no'], 'left on its new number');
        self::assertSame('001-004568921', $this->core()->accounts[2001]['no']);

        $jobs = static::getContainer()->get(EntityManagerInterface::class)->getRepository(ExportJob::class)->findBy(['kind' => ExportKind::BatchRollbackSkipped]);
        self::assertCount(1, $jobs);
        self::assertSame(ExportState::Completed, $jobs[0]->getState());
        $this->client->request('GET', sprintf('/exports/%d/download', $jobs[0]->getId()));
        $csv = file_get_contents($this->client->getResponse()->getFile()->getPathname());
        self::assertStringStartsWith("card_ref,old_account,new_account,line,reason\n", $csv);
        self::assertStringContainsString(',001-005688102,009-003821449,5,"001-005688102 is in use by another account again."', $csv);
    }

    public function testARollbackRequestCanBeRejected(): void
    {
        $batch = $this->completedBatch();
        $this->loginAs('officer');
        $this->post($batch, 'rollback', ['reason' => 'Not sure']);
        $this->loginAs('approver');
        $this->post($batch, 'rollback/reject', ['note' => 'The numbers are correct']);

        $batch = $this->reload($batch);
        self::assertSame(BatchStatus::Completed, $batch->getStatus());
        self::assertSame([], $this->core()->restores);
        $this->view($batch);
        self::assertSelectorTextContains('body', 'Rollback request rejected by approver: The numbers are correct');
    }

    public function testIdenticalDuplicateLinkRowsInCoreAreCountedOnceAndRenamedTogether(): void
    {
        // Core holds card 1002's link to account 2002 three times over (same card, account and xml1).
        $this->core()->duplicateLink(1002, 2002);
        $this->core()->duplicateLink(1002, 2002);

        $this->loginAs('officer');
        $batch = $this->upload(self::VALID_CSV);
        self::assertSame(BatchStatus::Validated, $batch->getStatus());
        self::assertSame(1, $this->rows($batch)[2]->getLinkedCards(), 'one card, however many identical link rows');

        $this->post($batch, 'submit');
        $this->loginAs('approver');
        $this->post($batch, 'approve');
        self::assertSame(BatchStatus::Completed, $this->reload($batch)->getStatus());

        $links = array_values(array_filter($this->core()->links, static fn ($l) => 2002 === $l['account']));
        self::assertCount(3, $links);
        foreach ($links as $link) {
            self::assertStringContainsString('<OLDACCTNO>001-009713450</>', $link['xml'], 'every copy follows the new number');
        }
    }

    public function testAnAccountNumberSharedBySeparateCoreRecordsIsRejectedAndNamesThem(): void
    {
        // A second account record (another customer) with the same number: not two cards on one account.
        $this->core()->addAccount(2998, '001-004568921');

        $this->loginAs('officer');
        $batch = $this->upload(<<<'CSV'
            card_ref,current_account,new_account
            1001,001-004568921,009-003821447
            1006,001-004568921,009-003821447
            CSV);

        self::assertSame(BatchStatus::Invalid, $batch->getStatus());
        foreach ($this->rows($batch) as $row) {
            $error = implode(' ', $row->getErrors());
            self::assertStringContainsString('current_account matches 2 core account records', $error);
            self::assertStringContainsString('seq 2001 / customer 100284 / branch 7', $error);
            self::assertStringContainsString('seq 2998 / customer 999999 / branch 7', $error);
            self::assertStringContainsString('fix the duplicate account number in core first', $error);
        }
    }

    public function testTheValidRowsOfABatchThatNeedsCorrectionCanProceedWithoutTheRest(): void
    {
        $this->loginAs('officer');
        $batch = $this->upload(<<<'CSV'
            card_ref,current_account,new_account
            1001,001-004568921,009-003821447
            1002,001-009713450,009-003821448
            ,001-005688102,009-003821449
            ,001-000000000,009-100000001
            1006,001-004568921,009-003821450
            CSV);
        self::assertSame(BatchStatus::Invalid, $batch->getStatus());
        self::assertSame(2, $batch->invalidRowCount());

        // The uploader proceeds with the valid rows; the confirmation says the rest are skipped.
        self::assertSelectorTextContains('button', 'Proceed with valid rows only');
        self::assertStringContainsString('need correction are skipped', $this->client->getCrawler()->filter('form[action$="/submit-valid"]')->attr('data-confirm-message-value'));
        $this->post($batch, 'submit-valid');
        $batch = $this->reload($batch);
        self::assertSame(BatchStatus::AwaitingApproval, $batch->getStatus());
        self::assertTrue($batch->skipsInvalidRows());
        self::assertSame(2, $batch->accountCount(), '001-004568921 has a row to correct (line 6), so its valid row is skipped too');

        $this->loginAs('approver');
        $this->post($batch, 'approve');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash.success', 'Exports of the migrated rows and the rows to correct are queued');

        self::assertSame(BatchStatus::Completed, $this->reload($batch)->getStatus());
        self::assertSame([
            ['from' => '001-005688102', 'to' => '009-003821449', 'user' => 'approver'],
            ['from' => '001-009713450', 'to' => '009-003821448', 'user' => 'approver'],
        ], $this->core()->renames);
        self::assertSame('001-004568921', $this->core()->accounts[2001]['no']);

        // Both exports were queued and (with the test's inline worker) generated.
        $jobs = static::getContainer()->get(EntityManagerInterface::class)->getRepository(ExportJob::class)->findBy([], ['id' => 'ASC']);
        self::assertSame([ExportKind::BatchMigrated, ExportKind::BatchCorrections], array_map(static fn (ExportJob $j) => $j->getKind(), $jobs));
        self::assertSame([ExportState::Completed, ExportState::Completed], array_map(static fn (ExportJob $j) => $j->getState(), $jobs));
        self::assertSame([2, 3], array_map(static fn (ExportJob $j) => $j->getRowsWritten(), $jobs));

        $this->client->request('GET', sprintf('/exports/%d/download', $jobs[0]->getId()));
        self::assertResponseIsSuccessful();
        $migrated = file_get_contents($this->client->getResponse()->getFile()->getPathname());
        self::assertStringStartsWith("card_ref,card_number,cardholder,old_account,new_account,linked_cards,applied_at,approved_by
", $migrated);
        self::assertStringContainsString(',001-009713450,009-003821448,1,', $migrated);
        self::assertStringContainsString(',001-005688102,009-003821449,', $migrated);
        self::assertStringNotContainsString('001-004568921', $migrated);

        $this->client->request('GET', sprintf('/exports/%d/download', $jobs[1]->getId()));
        $corrections = file_get_contents($this->client->getResponse()->getFile()->getPathname());
        self::assertStringStartsWith("card_ref,current_account,new_account,line,errors
", $corrections);
        self::assertStringContainsString('1001,001-004568921,009-003821447,2,"Skipped: another row of this account needs correction."', $corrections);
        self::assertStringContainsString(',001-000000000,009-100000001,5,', $corrections);
        self::assertStringContainsString('1006,001-004568921,009-003821450,6,', $corrections);

        $this->client->request('GET', '/exports');
        self::assertSelectorTextContains('tbody', 'Rows to correct · batch #'.$batch->getId());
    }

    public function testCoreChangesAfterValidationFailTheWholeBatchWithoutWriting(): void
    {
        $this->loginAs('officer');
        $batch = $this->upload(self::VALID_CSV);
        $this->post($batch, 'submit');

        // Someone creates an account with one of the new numbers while the batch waits for approval.
        // 001-005688102 → 009-003821449 is in the first chunk, so nothing has been renamed when it fails.
        $this->core()->addAccount(2999, '009-003821449');

        $this->loginAs('approver');
        $this->post($batch, 'approve');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash.error', '1 row(s) no longer match live core data.');

        $batch = $this->reload($batch);
        self::assertSame(BatchStatus::Failed, $batch->getStatus());
        self::assertSame([], $this->core()->renames, 'The failing chunk rolls back: its other account must not be renamed either.');
        self::assertSame('001-004568921', $this->core()->accounts[2001]['no']);
        self::assertNull($this->rows($batch)[0]->getAppliedAt());
    }

    public function testAFailingLaterChunkHaltsTheBatchAndAnApproverCanResumeIt(): void
    {
        $this->loginAs('officer');
        $batch = $this->upload(self::VALID_CSV);
        $this->post($batch, 'submit');

        // 001-009713450 → 009-003821448 is in the second chunk: the first chunk commits before it fails.
        $this->core()->addAccount(2999, '009-003821448');

        $this->loginAs('approver');
        $this->post($batch, 'approve');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash.error', 'Stopped after replacing 2 of 3 account(s)');

        $batch = $this->reload($batch);
        self::assertSame(BatchStatus::Halted, $batch->getStatus());
        self::assertSame(2, $batch->getAppliedAccountCount());
        self::assertSame(1, $batch->invalidRowCount());
        self::assertSame(['001-004568921', '001-005688102'], array_column($this->core()->renames, 'from'));
        self::assertNotNull($this->rows($batch)[0]->getAppliedAt());
        self::assertNull($this->rows($batch)[2]->getAppliedAt());

        $this->client->request('GET', sprintf('/migration/batches/%d/rows.csv?filter=pending', $batch->getId()));
        self::assertResponseIsSuccessful();
        self::assertSame(
            "card_ref,current_account,new_account,line,errors\n1002,001-009713450,009-003821448,4,\"new_account already exists in core.\"\n",
            $this->client->getInternalResponse()->getContent(),
        );

        // The uploader cannot resume it.
        $this->loginAs('officer');
        $this->post($batch, 'resume', expectRedirect: false);
        self::assertResponseStatusCodeSame(403);

        // Once the conflict is gone in core, an approver finishes the rest.
        $this->core()->removeAccount(2999);
        $this->loginAs('approver');
        $this->post($batch, 'resume');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash.success', 'remaining accounts were replaced');

        $batch = $this->reload($batch);
        self::assertSame(BatchStatus::Completed, $batch->getStatus());
        self::assertSame(0, $batch->invalidRowCount());
        self::assertSame(['001-004568921', '001-005688102', '001-009713450'], array_column($this->core()->renames, 'from'));
        self::assertSelectorTextContains('.audit', 'Halted by approver');
        self::assertSelectorTextContains('.audit', 'Resumed by approver');
        self::assertSelectorTextContains('.audit', '3 account(s) replaced in core, 4 card link(s) updated');
    }

    public function testSmallFilesAreValidatedDuringTheRequest(): void
    {
        $this->loginAs('officer');
        $batch = $this->upload("current_account,new_account\n001-004568921,009-003821447\n001-009713450,009-003821448\n");
        self::assertSame(BatchStatus::Validated, $batch->getStatus());
        self::assertSelectorNotExists('.flash.success'); // no "validated in the background" notice
        self::assertSame(2, $batch->getRowCount());
    }

    public function testFilesOfMoreThanFiveThousandRowsAreValidatedAcrossChunks(): void
    {
        $lines = ['current_account,new_account', '001-004568921,009-200000000'];
        for ($i = 1; $i <= 6000; ++$i) {
            $lines[] = sprintf('001-7%08d,009-7%08d', $i, $i); // not in core
        }
        $lines[] = '001-004568921,009-200000001'; // several chunks after line 2, and conflicts with it

        $this->loginAs('officer');
        $batch = $this->upload(implode("\n", $lines)."\n");
        self::assertSelectorTextContains('.flash.success', '6,002 rows are being validated in the background');

        self::assertSame(BatchStatus::Invalid, $batch->getStatus());
        self::assertSame(6002, $batch->getRowCount());
        self::assertSame(6001, $batch->invalidRowCount());
        self::assertSame(6001, $batch->accountCount());
        $rows = $this->rows($batch);
        self::assertTrue($rows[0]->isValid());
        self::assertSame(['current_account is mapped to 009-200000000 on line 2; one account can only get one new number.'], $rows[6001]->getErrors());

        // The page shows one page of rows at a time.
        $this->client->request('GET', sprintf('/migration/batches/%d', $batch->getId()));
        self::assertSelectorTextContains('.pagination', 'Showing 1–100 of 6,002 rows');
        self::assertCount(100, $this->client->getCrawler()->filter('tbody tr'));

        $this->client->request('GET', sprintf('/migration/batches/%d?invalid=1&page=61', $batch->getId()));
        self::assertSelectorTextContains('.pagination', 'Showing 6,001–6,001 of 6,001 rows');
        self::assertSelectorTextContains('tbody', 'current_account is mapped to 009-200000000 on line 2');

        $this->client->request('GET', sprintf('/migration/batches/%d/rows.csv', $batch->getId()));
        self::assertResponseIsSuccessful();
        self::assertSame(6002, substr_count($this->client->getInternalResponse()->getContent(), "\n"), 'header + 6,001 rows to correct');
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

    public function testApproversCannotUpload(): void
    {
        // Officers get the upload section.
        $this->loginAs('officer');
        $this->client->request('GET', '/migration');
        self::assertSelectorExists('form[name="mapping_upload"]');
        self::assertSelectorTextContains('body', 'Upload a replacement mapping file');
        $token = $this->client->getCrawler()->filter('input[name="mapping_upload[_token]"]')->attr('value');

        // Approvers see the batch list, but no upload section.
        $this->loginAs('approver');
        $this->client->request('GET', '/migration');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[name="mapping_upload"]');
        self::assertSelectorTextNotContains('body', 'Upload a replacement mapping file');
        self::assertSelectorExists('table');

        // Posting an upload anyway is refused, and no batch is created.
        $path = sys_get_temp_dir().'/ucpb-'.bin2hex(random_bytes(4)).'.csv';
        file_put_contents($path, self::VALID_CSV);
        $this->tempFiles[] = $path;
        $this->client->request('POST', '/migration', ['mapping_upload' => ['_token' => $token]], ['mapping_upload' => ['file' => new UploadedFile($path, 'mapping.csv', 'text/csv', null, true)]]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, static::getContainer()->get(EntityManagerInterface::class)->getRepository(MigrationBatch::class)->count([]));
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

    /** VALID_CSV uploaded by the officer, submitted, and approved (applied) by the approver. */
    private function completedBatch(): MigrationBatch
    {
        $this->loginAs('officer');
        $batch = $this->upload(self::VALID_CSV);
        $this->post($batch, 'submit');
        $this->loginAs('approver');
        $this->post($batch, 'approve');
        self::assertSame(BatchStatus::Completed, $this->reload($batch)->getStatus());

        return $batch;
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

    /** @return list<MigrationRow> in file order */
    private function rows(MigrationBatch $batch): array
    {
        return static::getContainer()->get(MigrationRowRepository::class)->findBy(['batch' => $batch], ['lineNumber' => 'ASC']);
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
