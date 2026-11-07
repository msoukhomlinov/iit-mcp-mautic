<?php

declare(strict_types=1);

namespace MauticPlugin\IntellectItMcpBundle\Tools;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\LeadBundle\Entity\Company;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Helper\IdentifyCompanyHelper;
use Mautic\LeadBundle\Model\CompanyModel;
use Mautic\LeadBundle\Model\FieldModel;
use Mautic\LeadBundle\Model\LeadModel;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;
use MauticPlugin\IntellectItMcpBundle\Service\McpUserContext;

const COMPANY_SCHEMA = [
    'type'                 => 'object',
    'required'             => ['fields'],
    'additionalProperties' => false,
    'properties'           => [
        'fields' => [
            'type'                 => 'object',
            'description'          => 'Company fields by alias (mautic_list_contact_fields with object "company"), e.g. {"companyname": "ACME GmbH", "companywebsite": "https://acme.example", "companycity": "Kiel", "companycountry": "Germany", "companyindustry": "Software"}. companyname is required for new companies.',
            'additionalProperties' => ['type' => ['string', 'number', 'boolean', 'null']],
        ],
        'contactIds' => ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => 100, 'description' => 'Link these contacts to the company (it becomes their primary company)'],
    ],
];

/**
 * Companies (Mautic's account records): search, read, create or update,
 * link contacts. Writes need the scope mcp:contacts:write; Mautic guards
 * companies with the contact permissions (lead:leads:*).
 */
class CompanyTools
{
    private const MAX = 100;

    public function __construct(
        private readonly McpUserContext $auth,
        private readonly CompanyModel $companies,
        private readonly LeadModel $leads,
        private readonly FieldModel $fields,
        private readonly CoreParametersHelper $parameters,
    ) {
    }

    /**
     * Search companies by name or Mautic search syntax.
     *
     * @return array<string, mixed>
     */
    public function searchCompanies(RequestContext $context, string $search = '', #[Schema(minimum: 1, maximum: 100)] int $limit = 20, #[Schema(minimum: 0)] int $offset = 0, bool $includeFields = false): array
    {
        $this->auth->require($context, ['lead:leads:viewown', 'lead:leads:viewother']);
        $result = $this->companies->getEntities([
            'start'            => $offset,
            'limit'            => min($limit, self::MAX),
            'filter'           => ['string' => $search],
            'withTotalCount'   => true,
            'ignore_paginator' => false,
        ]);
        $items = is_array($result) && array_key_exists('results', $result) ? $result['results'] : $result;
        $items = is_iterable($items) ? (is_array($items) ? array_values($items) : iterator_to_array($items, false)) : [];
        $total = is_array($result) && isset($result['count']) ? (int) $result['count'] : ($result instanceof \Countable ? count($result) : count($items));

        return ['total' => $total, 'companies' => array_map(fn (Company $c): array => $this->summary($c) + ($includeFields ? ['fields' => $this->profile($c)] : []), $items)];
    }

    /**
     * Get one company with all fields and its contacts (up to 100).
     *
     * @return array<string, mixed>
     */
    public function getCompany(RequestContext $context, int $id): array
    {
        $company = $this->find($id);
        $this->auth->requireEntityAccess($context, $company, 'lead:leads', 'view');
        $ids      = array_map(fn (array $r): int => (int) $r['lead_id'], $this->companies->getCompanyLeadRepository()->getCompanyLeads($id));
        $contacts = [];
        foreach (array_slice($ids, 0, self::MAX) as $leadId) {
            $lead = $this->leads->getEntity($leadId);
            if ($lead instanceof Lead) {
                $contacts[] = ['id' => $lead->getId(), 'email' => $lead->getEmail(), 'name' => trim($lead->getFirstname().' '.$lead->getLastname())];
            }
        }

        return $this->summary($company) + ['fields' => $this->profile($company), 'contacts_total' => count($ids), 'contacts' => $contacts];
    }

    /**
     * Create or update companies (up to 100 per call), matched by Mautic's unique company fields (company name by default). Optionally link contacts to each company. dryRun reports what would happen.
     *
     * @param list<array<string, mixed>> $companies Companies: {fields, contactIds}
     * @param bool                       $overwrite Overwrite existing values (false: only fill empty fields)
     * @param bool                       $dryRun    Validate and report, save nothing
     *
     * @return array<string, mixed>
     */
    public function upsertCompanies(
        RequestContext $context,
        #[Schema(definition: ['type' => 'array', 'minItems' => 1, 'maxItems' => self::MAX, 'items' => COMPANY_SCHEMA])] array $companies,
        bool $overwrite = true,
        bool $dryRun = false,
    ): array {
        $this->auth->user($context);
        $known   = $this->fields->getFieldList(false, false, ['isPublished' => true, 'object' => 'company']);
        $counts  = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0];
        $results = [];
        foreach (array_values($companies) as $i => $row) {
            try {
                $data = [];
                foreach ((array) ($row['fields'] ?? []) as $alias => $value) {
                    $data[strtolower((string) $alias)] = is_bool($value) ? (int) $value : $value;
                }
                $unknown = array_diff(array_keys($data), array_keys($known));
                if ([] !== $unknown) {
                    throw new ToolCallException(sprintf('Unknown company field(s): %s. See mautic_list_contact_fields with object "company".', implode(', ', $unknown)));
                }
                [, $found] = IdentifyCompanyHelper::findCompany($data, $this->companies);
                $company   = [] !== $found ? end($found) : new Company();
                $isNew     = !$company->getId();
                if ($isNew) {
                    if (in_array($data['companyname'] ?? '', ['', null], true)) {
                        throw new ToolCallException('New companies need "companyname".');
                    }
                    $this->auth->require($context, 'lead:leads:create');
                } else {
                    $this->auth->requireEntityAccess($context, $company, 'lead:leads', 'edit');
                    if (!$overwrite) {
                        $data = array_filter($data, fn ($v, string $alias): bool => in_array($company->getFieldValue($alias), [null, ''], true), ARRAY_FILTER_USE_BOTH);
                    }
                }
                $changed = $isNew ? array_keys($data) : array_keys(array_filter($data, fn ($v, string $alias): bool => (string) $company->getFieldValue($alias) !== (string) $v, ARRAY_FILTER_USE_BOTH));
                $link    = $this->contacts($context, (array) ($row['contactIds'] ?? []), $isNew ? [] : array_map(fn (array $r): int => (int) $r['lead_id'], $this->companies->getCompanyLeadRepository()->getCompanyLeads((int) $company->getId())));
                $status  = $isNew ? 'created' : ([] === $changed && [] === $link ? 'unchanged' : 'updated');

                if (!$dryRun && 'unchanged' !== $status) {
                    $this->companies->setFieldValues($company, $data, false);
                    $this->companies->saveEntity($company);
                    foreach ($link as $lead) {
                        $this->companies->addLeadToCompany($company, $lead);
                    }
                    $this->auth->audit('company', (int) $company->getId(), $isNew ? 'create' : 'update', ['fields' => $changed, 'contacts' => array_map(fn (Lead $l): int => (int) $l->getId(), $link)]);
                }
                ++$counts[$status];
                $results[] = array_filter([
                    'index'          => $i,
                    'status'         => $status,
                    'id'             => $company->getId(),
                    'name'           => $data['companyname'] ?? $company->getName(),
                    'changed_fields' => 'updated' === $status ? $changed : null,
                    'links_contacts' => array_map(fn (Lead $l): int => (int) $l->getId(), $link) ?: null,
                ], fn ($v): bool => null !== $v);
            } catch (ToolCallException $e) {
                ++$counts['failed'];
                $results[] = ['index' => $i, 'status' => 'failed', 'error' => $e->getMessage()];
            } catch (\Throwable $e) {
                ++$counts['failed'];
                $results[] = ['index' => $i, 'status' => 'failed', 'error' => 'Mautic rejected the company: '.$e->getMessage()];
            }
        }

        return [
            'dry_run' => $dryRun,
            'message' => ($dryRun ? 'Dry run, nothing saved: ' : 'Saved: ').vsprintf('%d created, %d updated, %d unchanged, %d failed.', array_values($counts)),
            'counts'  => $counts,
            'results' => $results,
        ];
    }

    /**
     * Link contacts to a company (up to 100). It becomes their primary company and their "company" field.
     *
     * @param list<int> $contactIds Contact IDs
     *
     * @return array<string, mixed>
     */
    public function addContactsToCompany(RequestContext $context, int $companyId, #[Schema(minItems: 1, maxItems: self::MAX)] array $contactIds): array
    {
        $company = $this->find($companyId);
        $this->auth->requireEntityAccess($context, $company, 'lead:leads', 'edit');
        $leads = $this->contacts($context, $contactIds, array_map(fn (array $r): int => (int) $r['lead_id'], $this->companies->getCompanyLeadRepository()->getCompanyLeads($companyId)));
        foreach ($leads as $lead) {
            $this->companies->addLeadToCompany($company, $lead);
        }
        $ids = array_map(fn (Lead $l): int => (int) $l->getId(), $leads);
        if ([] !== $ids) {
            $this->auth->audit('company', $companyId, 'add_contacts', ['contacts' => $ids]);
        }

        return ['company' => $this->summary($company), 'added' => $ids, 'message' => sprintf('%d contact(s) linked.', count($ids))];
    }

    /**
     * Unlink contacts from a company (up to 100).
     *
     * @param list<int> $contactIds Contact IDs
     *
     * @return array<string, mixed>
     */
    public function removeContactsFromCompany(RequestContext $context, int $companyId, #[Schema(minItems: 1, maxItems: self::MAX)] array $contactIds): array
    {
        $company = $this->find($companyId);
        $this->auth->requireEntityAccess($context, $company, 'lead:leads', 'edit');
        $members = array_map(fn (array $r): int => (int) $r['lead_id'], $this->companies->getCompanyLeadRepository()->getCompanyLeads($companyId));
        $removed = [];
        foreach (array_unique(array_map('intval', $contactIds)) as $id) {
            if (!in_array($id, $members, true)) {
                continue;
            }
            $lead = $this->leads->getEntity($id);
            if ($lead instanceof Lead) {
                $this->auth->requireEntityAccess($context, $lead, 'lead:leads', 'edit');
                $this->companies->removeLeadFromCompany($company, $lead);
                $removed[] = $id;
            }
        }
        if ([] !== $removed) {
            $this->auth->audit('company', $companyId, 'remove_contacts', ['contacts' => $removed]);
        }

        return ['company' => $this->summary($company), 'removed' => $removed, 'message' => sprintf('%d contact(s) unlinked.', count($removed))];
    }

    /**
     * Contacts to link: existing, editable, not linked yet.
     *
     * @param array<mixed> $ids
     * @param list<int>    $members
     *
     * @return list<Lead>
     */
    private function contacts(RequestContext $context, array $ids, array $members): array
    {
        $leads = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            if (in_array($id, $members, true)) {
                continue;
            }
            $lead = $this->leads->getEntity($id);
            if (!$lead instanceof Lead || !$lead->getId()) {
                throw new ToolCallException(sprintf('Contact %d not found.', $id));
            }
            $this->auth->requireEntityAccess($context, $lead, 'lead:leads', 'edit');
            $leads[] = $lead;
        }

        return $leads;
    }

    private function find(int $id): Company
    {
        $company = $this->companies->getEntity($id);
        if (!$company instanceof Company || !$company->getId()) {
            throw new ToolCallException(sprintf('Company %d not found.', $id));
        }

        return $company;
    }

    /** @return array<string, mixed> */
    private function summary(Company $company): array
    {
        return [
            'id'      => $company->getId(),
            'name'    => $company->getName(),
            'website' => $company->getWebsite(),
            'city'    => $company->getCity(),
            'country' => $company->getCountry(),
            'url'     => rtrim((string) $this->parameters->get('site_url'), '/').'/s/companies/view/'.$company->getId(),
        ];
    }

    /** @return array<string, mixed> */
    private function profile(Company $company): array
    {
        $out = [];
        foreach ($company->getProfileFields() as $alias => $value) {
            if (null !== $value && '' !== $value && 'id' !== $alias) {
                $out[$alias] = $value;
            }
        }

        return $out;
    }
}
