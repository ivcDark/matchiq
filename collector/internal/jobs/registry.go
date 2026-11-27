// Package jobs — реестр job_type → сборщик и исполнение команды.
package jobs

import (
	"context"
	"fmt"

	"github.com/ivcDark/matchiq/collector/internal/dto"
	"github.com/ivcDark/matchiq/collector/internal/sstats"
)

// Source — то, что сборщикам нужно от клиента источника (в тестах подменяется).
type Source interface {
	Leagues(ctx context.Context, name string) ([]sstats.LsLeague, error)
	Seasons(ctx context.Context, leagueID string) ([]sstats.LsSeason, error)
	SeasonStructure(ctx context.Context, seasonID string) (sstats.LsSeasonStructure, error)
	Standings(ctx context.Context, seasonID string) ([]sstats.LsStandings, error)
	Fixtures(ctx context.Context, q sstats.FixturesQuery) ([]sstats.LsFixture, error)
	GamesByFlashIDs(ctx context.Context, flashIDs []string) ([]sstats.GameRef, error)
	GameInfo(ctx context.Context, matchID string) (sstats.LsGameInfo, error)
	GameLineups(ctx context.Context, matchID string) (sstats.LsGameLineups, error)
	Players(ctx context.Context, teamID string) ([]sstats.LsSquadPlayer, error)
}

// Output — нормализованный результат сборщика: сущности и ошибки отдельных сущностей.
type Output struct {
	Entities []dto.Entity
	Errors   []dto.EntityError
}

func (o *Output) Add(e dto.Entity)           { o.Entities = append(o.Entities, e) }
func (o *Output) AddError(e dto.EntityError) { o.Errors = append(o.Errors, e) }

// Collector обрабатывает один job_type. Ошибка — «прогон не состоялся целиком».
type Collector interface {
	Collect(ctx context.Context, src Source, cmd dto.Command) (*Output, error)
}

// PermanentError помечает ошибку команды, повтор которой бессмысленен.
type PermanentError struct{ Msg string }

func (e *PermanentError) Error() string { return e.Msg }

type Registry map[string]Collector

func DefaultRegistry() Registry {
	return Registry{
		dto.JobSyncCatalog:   SyncCatalog{},
		dto.JobSyncStructure: SyncStructure{},
		dto.JobSyncFixtures:  SyncFixtures{},

		dto.JobSyncMatchDetails: SyncMatchDetails{},
		dto.JobSyncSquads:       SyncSquads{},
	}
}

func (r Registry) Get(jobType string) (Collector, error) {
	c, ok := r[jobType]
	if !ok {
		return nil, &PermanentError{Msg: fmt.Sprintf("unknown job_type %q", jobType)}
	}

	return c, nil
}
