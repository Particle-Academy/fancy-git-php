<?php

declare(strict_types=1);

namespace FancyGit\Provider;

/**
 * Issue tracking, as a SEPARATE contract from {@see GitProvider}.
 *
 * Not added to `GitProvider` on purpose. That interface is implemented by every
 * provider, including ones outside this package, and adding a method to it
 * would break each of them for a capability many hosts do not offer — a
 * self-hosted remote with no tracker is a perfectly good `GitProvider`.
 *
 * So an adapter opts in, and a caller checks `instanceof IssueProvider` before
 * reaching for these. That is the same shape the rest of the suite uses for
 * optional capability, and it keeps "this host has no tracker" a first-class
 * answer rather than a method that throws.
 *
 * ## The normalized issue shape
 *
 * Deliberately thinner than any one host's model. GitHub has milestones and
 * state reasons, GitLab has weights and epics, Bitbucket has kinds and
 * priorities — none of which survive a move between hosts. What they all agree
 * on is normalized; the rest belongs in `extensions`, where a consumer that
 * knows its host can reach it without the contract pretending it is portable.
 *
 *     array{
 *       id: string, number: int, title: string, state: 'open'|'closed',
 *       webUrl: string, author: string, labels: list<string>,
 *       assignees: list<string>, createdAt: string, updatedAt: string,
 *       extensions?: array<string,mixed>,
 *     }
 */
interface IssueProvider
{
    /**
     * @param  array{provider:string,owner:string,name:string,baseUrl?:string}  $ref
     * @param  array{state?:string,labels?:list<string>,assignee?:string,search?:string,cursor?:string,limit?:int}  $query
     * @return array{items:list<array<string,mixed>>,nextCursor?:string,total?:int}
     */
    public function listIssues(array $ref, array $query = []): array;

    /**
     * @param  array{provider:string,owner:string,name:string,baseUrl?:string}  $ref
     * @return array<string,mixed>
     */
    public function getIssue(array $ref, int $number): array;

    /**
     * @param  array{provider:string,owner:string,name:string,baseUrl?:string}  $ref
     * @param  array{title:string,body?:string,labels?:list<string>,assignees?:list<string>}  $input
     * @return array<string,mixed>
     */
    public function createIssue(array $ref, array $input): array;

    /**
     * A PARTIAL update — only the keys present are sent.
     *
     * Echoing the whole issue back would clobber whatever someone else changed
     * between the read and the write, and on an issue tracker that someone is
     * usually a person mid-conversation.
     *
     * @param  array{provider:string,owner:string,name:string,baseUrl?:string}  $ref
     * @param  array{title?:string,body?:string,state?:string,labels?:list<string>,assignees?:list<string>}  $input
     * @return array<string,mixed>
     */
    public function updateIssue(array $ref, int $number, array $input): array;

    /**
     * @param  array{provider:string,owner:string,name:string,baseUrl?:string}  $ref
     * @return array{id:string,webUrl:string}
     */
    public function commentOnIssue(array $ref, int $number, string $body): array;
}
