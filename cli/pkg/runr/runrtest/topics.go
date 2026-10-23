package runrtest

import "strings"

// One topic of the organization (TopicController::show / update): the detail lists the
// subscribing triggers; only the description changes.

func init() {
	handle("GET /topics/{topic}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		t := s.trigTopic(q.Segs[1])
		if t == nil {
			return 0, nil, errNotFound()
		}
		out := s.topicJSON(t)
		subs := []any{}
		for _, sub := range s.trigSubscriptions(t.Name) {
			pid, _ := sub["project_id"].(string)
			row := map[string]any{"trigger": sub["trigger"], "project_id": pid, "enabled": true, "start_from": "latest"}
			if p := s.projectByID(pid); p != nil {
				row["project_name"] = p.data.Name
				for _, tr := range s.trig().lists[pid] {
					if tr.name == sub["trigger"] {
						row["trigger_id"], row["enabled"] = tr.id, tr.enabled
						if sf, ok := tr.config["start_from"]; ok {
							row["start_from"] = sf
						}
					}
				}
			}
			subs = append(subs, row)
		}
		out["subscriptions"] = subs
		return 200, map[string]any{"data": out}, nil
	})
	handle("PATCH /topics/{topic}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		t := s.trigTopic(q.Segs[1])
		if t == nil {
			return 0, nil, errNotFound()
		}
		if v, ok := q.Body["description"]; ok {
			d, isStr := v.(string)
			if v != nil && !isStr {
				return 0, nil, errValidation("description", "The description field must be a string.")
			}
			if len(d) > 500 {
				return 0, nil, errValidation("description", "The description field must not be greater than 500 characters.")
			}
			t.Description = strings.TrimSpace(d)
		}
		out := s.topicJSON(t)
		delete(out, "subscriptions")
		return 200, map[string]any{"data": out}, nil
	})
}
