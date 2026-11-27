package jobs

import (
	"encoding/json"
	"testing"

	"github.com/ivcDark/matchiq/collector/internal/dto"
	"github.com/ivcDark/matchiq/collector/internal/sstats"
)

const squadJSON = `[
  {"id": "PlGk0001", "name": "Denis Adamov", "shortName": "Adamov D.", "role": "Goalkeeper", "birthdate": "1999-02-02",
   "country": {"num": 158, "id": "russia", "name": "Russia", "abbr2": "ru"}, "currentTeamId": "TeZenit1", "profileUpdatedAt": "2026-11-25T14:50:54+03:00"},
  {"id": "PlFw0001", "name": "Pedrinho", "role": "Forward", "photoUrl": "x.png", "currentTeamId": "TeZenit1", "profileUpdatedAt": null},
  {"id": "PlNo0001", "name": "Unknown Guy", "role": null, "currentTeamId": "TeZenit1", "profileUpdatedAt": null},
  {"id": "CoZen001", "name": "Coach Z.", "role": "Coach", "currentTeamId": "TeZenit1"},
  {"id": "PlGk0001", "name": "Dup", "role": "Goalkeeper"},
  {"id": "PlBad001", "name": "  ", "role": "Defender"}
]`

func TestSyncSquadsEmitsPlayersAndSquad(t *testing.T) {
	var people []sstats.LsSquadPlayer
	if err := json.Unmarshal([]byte(squadJSON), &people); err != nil {
		t.Fatal(err)
	}
	src := fakeSource{
		squads:    map[string][]sstats.LsSquadPlayer{"TeZenit1": people, "TeEmpty01": {}},
		squadErrs: map[string]error{"TeBroken1": &sstats.APIError{Message: "not found"}},
	}

	msgs := runCommand(t, src, dto.JobSyncSquads, dto.SquadsPayload{
		TeamExternalIDs: []string{"TeZenit1", "TeEmpty01", "TeBroken1", "TeZenit1"},
	}, 200)

	batch := msgs[0].(*dto.Batch)
	players := map[string]dto.PlayerData{}
	var squad *dto.SquadData
	var order []string
	for _, e := range batch.Entities {
		order = append(order, e.EntityType)
		switch d := e.Data.(type) {
		case dto.PlayerData:
			players[e.ExternalID] = d
		case dto.SquadData:
			squad = &d
		}
	}

	if len(players) != 3 {
		t.Fatalf("players = %v", players)
	}
	if gk := players["PlGk0001"]; !gk.HasProfile || gk.Position != dto.PositionGK || gk.BirthDate != "1999-02-02" || gk.CountryExternalID != "158" {
		t.Fatalf("goalkeeper = %+v", gk)
	}
	if fw := players["PlFw0001"]; fw.HasProfile || fw.Position != dto.PositionFW || fw.TeamExternalID != "TeZenit1" {
		t.Fatalf("forward without profile = %+v", fw)
	}
	if squad == nil || squad.TeamExternalID != "TeZenit1" || len(squad.Players) != 3 {
		t.Fatalf("squad = %+v", squad)
	}
	if squad.Players[2].PlayerExternalID != "PlNo0001" || squad.Players[2].Position != "" {
		t.Fatalf("squad players = %+v", squad.Players)
	}
	if order[len(order)-1] != dto.EntitySquad {
		t.Fatalf("squad must come after players: %v", order)
	}
	for _, e := range batch.Entities {
		if e.EntityType == dto.EntityCoach {
			t.Fatal("coaches must be skipped")
		}
	}

	if len(batch.Errors) != 3 {
		t.Fatalf("errors = %+v", batch.Errors)
	}
}

func TestSyncSquadsRequiresTeams(t *testing.T) {
	msgs := runCommand(t, fakeSource{}, dto.JobSyncSquads, dto.SquadsPayload{}, 200)
	if f, ok := msgs[0].(dto.Failed); !ok || f.Retryable {
		t.Fatalf("msgs = %+v", msgs)
	}
}

func TestSyncSquadsStopsOnRetryableError(t *testing.T) {
	src := fakeSource{squadErrs: map[string]error{"TeZenit1": &sstats.StatusError{Code: 429, Message: "slow down"}}}
	msgs := runCommand(t, src, dto.JobSyncSquads, dto.SquadsPayload{TeamExternalIDs: []string{"TeZenit1"}}, 200)
	if f, ok := msgs[0].(dto.Failed); !ok || !f.Retryable {
		t.Fatalf("msgs = %+v", msgs)
	}
}
