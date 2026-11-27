package jobs

import (
	"encoding/json"
	"testing"

	"github.com/ivcDark/matchiq/collector/internal/dto"
	"github.com/ivcDark/matchiq/collector/internal/sstats"
)

const playersJSON = `[
  {"firstName":"Igor","lastName":"Diveev","nationality":"Russia","height":"194","birthDate":"1999-09-27","photoUrl":"https://sstats.net/assets/player_photos/826.png",
   "unavailabilities":[{"start":"2026-11-01","end":null,"typeId":1,"typeName":"Knee Injury"},{"start":"2026-01-01","end":"2026-02-01","typeId":1,"typeName":"Injury"},
                       {"start":"2026-11-20","end":"2099-01-01","typeId":2,"typeName":"Red Card"}],"id":826,"name":"I. Diveev"},
  {"firstName":"Wílmar Enrique","lastName":"Barrios Terán","nationality":"Colombia","height":"178","birthDate":"1993-10-16","photoUrl":null,"id":1209,"name":"W. Barrios"},
  {"firstName":null,"lastName":null,"nationality":null,"height":null,"birthDate":null,"photoUrl":"x.png","id":"9","name":"Pedrinho"},
  {"firstName":"Marcus Wendel","lastName":"Valle da Silva","birthDate":"1997-08-28","id":3339,"name":"Wendel"},
  {"firstName":"Luiz Henrique","lastName":"Rosa da Silva","birthDate":"1993-10-16","id":3340,"name":"L. Henrique"},
  {"firstName":"Dup","lastName":"Dup","id":826,"name":"Dup"},
  {"firstName":null,"lastName":null,"id":77,"name":"  "}
]`

const lsPlayersJSON = `[
  {"id":"FlDiv001","name":"Diveev Igor","role":"Defender","birthdate":"1999-09-27"},
  {"id":"FlBar001","name":"Barrios Wilmar","role":"Midfielder","birthdate":"1993-10-16"},
  {"id":"FlPed001","name":"Pedrinho","role":"Forward","birthdate":null},
  {"id":"FlWen001","name":"Wendel","role":"Midfielder","birthdate":"1997-08-28"},
  {"id":"FlCoa001","name":"Semak Sergei","role":"Coach"}
]`

func TestSyncSquadsFromPlayersListWithLsPositions(t *testing.T) {
	var people []sstats.Player
	var ls []sstats.LsSquadPlayer
	if err := json.Unmarshal([]byte(playersJSON), &people); err != nil {
		t.Fatal(err)
	}
	if err := json.Unmarshal([]byte(lsPlayersJSON), &ls); err != nil {
		t.Fatal(err)
	}
	src := fakeSource{
		squads:    map[string][]sstats.Player{"596": people, "597": {}},
		squadErrs: map[string]error{"598": &sstats.APIError{Message: "not found"}},
		lsSquads:  map[string][]sstats.LsSquadPlayer{"Zen00001": ls},
	}

	msgs := runCommand(t, src, dto.JobSyncSquads, dto.SquadsPayload{
		TeamExternalIDs: []string{"596", "597", "598", "596"},
		TeamFlashIDs:    map[string]string{"596": "Zen00001"},
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
	if order[len(order)-1] != dto.EntitySquad {
		t.Fatalf("squad must go after players: %v", order)
	}

	diveev := players["826"]
	if diveev.PeopleSource != dto.PeopleSStatsAPI || diveev.Name != "Igor Diveev" || diveev.ShortName != "I. Diveev" || diveev.FirstName != "Igor" ||
		diveev.LastName != "Diveev" || diveev.Nationality != "Russia" || diveev.HeightCM == nil || *diveev.HeightCM != 194 ||
		diveev.BirthDate != "1999-09-27" || diveev.FlashID != "FlDiv001" || diveev.Position != dto.PositionDF || diveev.TeamExternalID != "596" || !diveev.HasProfile {
		t.Fatalf("diveev = %+v", diveev)
	}
	if b := players["1209"]; b.FlashID != "FlBar001" || b.Position != dto.PositionMF {
		t.Fatalf("diacritics and a double surname must still match Ls: %+v", b)
	}
	if p := players["9"]; p.Name != "Pedrinho" || p.FlashID != "" || p.Position != "" || p.PhotoURL != "" {
		t.Fatalf("no birth date and no initial — no Ls match: %+v", p)
	}
	if w := players["3339"]; w.FlashID != "FlWen001" || w.Position != dto.PositionMF {
		t.Fatalf("playing name + unique birth date in the squad must match Ls: %+v", w)
	}
	if h := players["3340"]; h.FlashID != "" {
		t.Fatalf("birth date shared with Barrios — ambiguous, no word overlap anyway: %+v", h)
	}

	if squad == nil || squad.PeopleSource != dto.PeopleSStatsAPI || squad.TeamExternalID != "596" || len(squad.Players) != 5 {
		t.Fatalf("squad = %+v", squad)
	}
	abs := squad.Players[0].Absences
	if len(abs) != 2 || abs[0] != (dto.AbsenceData{Type: dto.AbsenceInjury, Reason: "Knee Injury", Since: "2026-11-01"}) ||
		abs[1].Type != dto.AbsenceSuspension || abs[1].ExpectedReturn != "2099-01-01" {
		t.Fatalf("absences = %+v", abs)
	}
	if squad.Players[1].Absences != nil {
		t.Fatalf("no unavailabilities — nil: %+v", squad.Players[1])
	}

	errs := map[string]bool{}
	for _, e := range batch.Errors {
		errs[e.EntityType+":"+e.ExternalID+":"+e.Phase] = true
	}
	if len(batch.Errors) != 3 || !errs["squad:597:normalize"] || !errs["squad:598:fetch"] || !errs["player:77:normalize"] {
		t.Fatalf("errors = %+v", batch.Errors)
	}
}

func TestSyncSquadsLsFailureKeepsSquad(t *testing.T) {
	var people []sstats.Player
	_ = json.Unmarshal([]byte(playersJSON), &people)
	src := fakeSource{
		squads: map[string][]sstats.Player{"596": people},
		lsErrs: map[string]error{"Zen00001": &sstats.StatusError{Code: 502, Message: "bad gateway"}},
	}
	msgs := runCommand(t, src, dto.JobSyncSquads, dto.SquadsPayload{
		TeamExternalIDs: []string{"596"}, TeamFlashIDs: map[string]string{"596": "Zen00001"},
	}, 200)
	batch := msgs[0].(*dto.Batch)
	last := batch.Entities[len(batch.Entities)-1]
	if last.EntityType != dto.EntitySquad || len(batch.Errors) != 2 {
		t.Fatalf("entities=%+v errors=%+v", batch.Entities, batch.Errors)
	}
}

func TestSyncSquadsRequiresTeams(t *testing.T) {
	msgs := runCommand(t, fakeSource{}, dto.JobSyncSquads, dto.SquadsPayload{}, 200)
	if f, ok := msgs[0].(dto.Failed); !ok || f.Retryable {
		t.Fatalf("expected permanent failure, got %#v", msgs[0])
	}
}

func TestSyncSquadsStopsOnRetryableError(t *testing.T) {
	src := fakeSource{squadErrs: map[string]error{"596": &sstats.StatusError{Code: 429, Message: "slow down"}}}
	msgs := runCommand(t, src, dto.JobSyncSquads, dto.SquadsPayload{TeamExternalIDs: []string{"596"}}, 200)
	if f, ok := msgs[0].(dto.Failed); !ok || !f.Retryable {
		t.Fatalf("expected retryable failure, got %#v", msgs[0])
	}
}
