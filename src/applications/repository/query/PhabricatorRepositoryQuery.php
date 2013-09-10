<?php

final class PhabricatorRepositoryQuery
  extends PhabricatorCursorPagedPolicyAwareQuery {

  private $ids;
  private $phids;
  private $callsigns;

  const STATUS_OPEN   = 'status-open';
  const STATUS_CLOSED = 'status-closed';
  const STATUS_ALL    = 'status-all';
  private $status = self::STATUS_ALL;

  private $needMostRecentCommits;
  private $needCommitCounts;

  public function withIDs(array $ids) {
    $this->ids = $ids;
    return $this;
  }

  public function withPHIDs(array $phids) {
    $this->phids = $phids;
    return $this;
  }

  public function withCallsigns(array $callsigns) {
    $this->callsigns = $callsigns;
    return $this;
  }

  protected function getReversePaging() {
    return true;
  }

  public function withStatus($status) {
    $this->status = $status;
    return $this;
  }

  public function needCommitCounts($need_counts) {
    $this->needCommitCounts = $need_counts;
    return $this;
  }

  public function needMostRecentCommits($need_commits) {
    $this->needMostRecentCommits = $need_commits;
    return $this;
  }


  protected function loadPage() {
    $table = new PhabricatorRepository();
    $conn_r = $table->establishConnection('r');

    $data = queryfx_all(
      $conn_r,
      'SELECT * FROM %T r %Q %Q %Q %Q',
      $table->getTableName(),
      $this->buildJoinsClause($conn_r),
      $this->buildWhereClause($conn_r),
      $this->buildOrderClause($conn_r),
      $this->buildLimitClause($conn_r));

    $repositories = $table->loadAllFromArray($data);

    if ($this->needCommitCounts) {
      $sizes = ipull($data, 'size', 'id');
      foreach ($repositories as $id => $repository) {
        $repository->attachCommitCount(nonempty($sizes[$id], 0));
      }
    }

    if ($this->needMostRecentCommits) {
      $commit_ids = ipull($data, 'lastCommitID', 'id');
      $commit_ids = array_filter($commit_ids);
      if ($commit_ids) {
        $commits = id(new DiffusionCommitQuery())
          ->setViewer($this->getViewer())
          ->withIDs($commit_ids)
          ->execute();
      } else {
        $commits = array();
      }
      foreach ($repositories as $id => $repository) {
        $commit = null;
        if (idx($commit_ids, $id)) {
          $commit = idx($commits, $commit_ids[$id]);
        }
        $repository->attachMostRecentCommit($commit);
      }
    }


    return $repositories;
  }

  public function willFilterPage(array $repositories) {
    assert_instances_of($repositories, 'PhabricatorRepository');

    // TODO: Denormalize repository status into the PhabricatorRepository
    // table so we can do this filtering in the database.
    foreach ($repositories as $key => $repo) {
      $status = $this->status;
      switch ($status) {
        case self::STATUS_OPEN:
          if (!$repo->isTracked()) {
            unset($repositories[$key]);
          }
          break;
        case self::STATUS_CLOSED:
          if ($repo->isTracked()) {
            unset($repositories[$key]);
          }
          break;
        case self::STATUS_ALL:
          break;
        default:
          throw new Exception("Unknown status '{$status}'!");
      }
    }

    return $repositories;
  }

  private function buildJoinsClause(AphrontDatabaseConnection $conn_r) {
    $joins = array();

    $join_summary_table = $this->needCommitCounts ||
                          $this->needMostRecentCommits;

    if ($join_summary_table) {
      $joins[] = qsprintf(
        $conn_r,
        'LEFT JOIN %T summary ON r.id = summary.repositoryID',
        PhabricatorRepository::TABLE_SUMMARY);
    }

    return implode(' ', $joins);
  }

  private function buildWhereClause(AphrontDatabaseConnection $conn_r) {
    $where = array();

    if ($this->ids) {
      $where[] = qsprintf(
        $conn_r,
        'r.id IN (%Ld)',
        $this->ids);
    }

    if ($this->phids) {
      $where[] = qsprintf(
        $conn_r,
        'r.phid IN (%Ls)',
        $this->phids);
    }

    if ($this->callsigns) {
      $where[] = qsprintf(
        $conn_r,
        'r.callsign IN (%Ls)',
        $this->callsigns);
    }

    $where[] = $this->buildPagingClause($conn_r);

    return $this->formatWhereClause($where);
  }

}
